<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Write a message to the Gravity Forms log and the PHP error log
 *
 * The Gravity Forms logger silently discards everything unless logging is
 * switched on for Gravity Forms in its settings, so every message also goes to
 * the PHP error log (wp-content/debug.log when WP_DEBUG_LOG is on). Each
 * submission is deliberately traceable there.
 *
 * @param string $message
 * @param bool   $isError
 * @return void
 */
function klypGFToHubspotLog($message, $isError = true)
{
    $message = 'klyp-gf-to-hubspot: ' . $message;

    if (class_exists('GFCommon')) {
        if ($isError) {
            GFCommon::log_error($message);
        } else {
            GFCommon::log_debug($message);
        }
    }

    error_log($message);
}

/**
 * Whether to log full submission payloads and rejected values
 *
 * Those lines contain the personal data a visitor submitted, so verbose
 * logging follows WP_DEBUG rather than being on in production by default.
 * Outcomes — sent, rejected, failed — are always logged either way.
 *
 * @return bool
 */
function klypGFToHubspotLogPayloads()
{
    $default = defined('WP_DEBUG') && WP_DEBUG;

    /**
     * Filter whether submission payloads are written to the log.
     *
     * @param bool $enabled
     */
    return (bool) apply_filters('klyp_gftohs_log_payloads', $default);
}

/**
 * Hubspot API client.
 *
 * Two entirely different Hubspot endpoints are used here:
 *
 *  - Form definitions are read from the authenticated Marketing Forms v3 API
 *    (api.hubapi.com) using a Private App access token. The legacy Forms v2
 *    API this plugin previously used only accepted `hapikey` authentication,
 *    which Hubspot sunset, and it does not accept bearer tokens.
 *  - Form submissions go to the public Forms submission endpoint
 *    (api.hsforms.com), which is unauthenticated and unchanged.
 */
class klypHubspot
{
    /**
     * Authenticated API host, used for reading form definitions.
     */
    const API_BASE = 'https://api.hubapi.com/';

    /**
     * Public, unauthenticated form submission endpoint.
     */
    const SUBMIT_BASE = 'https://api.hsforms.com/submissions/v3/integration/submit/';

    /**
     * How long a form's field list is cached, in seconds.
     */
    const CACHE_TTL = 900;

    /**
     * Shape of the cached field objects.
     *
     * Bump this whenever normaliseFields() changes what it stores, so an
     * update cannot read entries cached in the previous shape.
     */
    const CACHE_SCHEMA = 2;

    /**
     * @var string Hubspot Private App access token.
     */
    public $accessToken = '';

    /**
     * @var string Hubspot portal (hub) ID.
     */
    public $portalId = '';

    /**
     * @var string Hubspot form GUID for the form being submitted.
     */
    public $hsFormId = '';

    /**
     * @var GF_Field[] Gravity Forms field objects for the submitted form.
     */
    public $gfFormFields = array();

    /**
     * @var string Gravity Forms field ID holding the email address.
     */
    public $gfEmailField = '';

    /**
     * @var string Hubspot property name holding the email address.
     */
    public $hsEmailField = '';

    /**
     * @var array Gravity Forms entry being sent.
     */
    public $entry = array();

    /**
     * @var string Human readable description of the last failure.
     */
    public $lastError = '';

    /**
     * @var array|null Hubspot field definitions keyed by property name.
     */
    private $fieldTypeMap = null;

    /**
     * Construct
     *
     * @return void
     */
    public function __construct()
    {
        $this->accessToken = (string) get_option('klyp_gftohs_access_token', '');
        $this->portalId    = (string) get_option('klyp_gftohs_portal_id', '');
    }

    /**
     * Base URL for authenticated API requests
     *
     * @return string
     */
    public function apiBase()
    {
        /**
         * Filter the Hubspot API base URL.
         *
         * @param string $base
         */
        return trailingslashit(apply_filters('klyp_gftohs_api_base', self::API_BASE));
    }

    /**
     * Whether the plugin is configured well enough to read form definitions
     *
     * @return bool
     */
    public function hasCredentials()
    {
        return $this->accessToken !== '';
    }

    /**
     * Perform an authenticated GET request
     *
     * @param string $path
     * @return array|WP_Error
     */
    private function remoteGet($path)
    {
        return wp_remote_get(
            $this->apiBase() . ltrim($path, '/'),
            array(
                'timeout' => 10,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->accessToken,
                    'Content-Type'  => 'application/json',
                ),
            )
        );
    }

    /**
     * Perform a POST request
     *
     * @param string $url
     * @param array  $body
     * @param array  $headers
     * @return array|WP_Error
     */
    private function remotePost($url, $body, $headers = array())
    {
        $headers = array_merge(array('Content-Type' => 'application/json'), $headers);

        return wp_remote_post(
            $url,
            array(
                'timeout' => 10,
                'body'    => wp_json_encode($body),
                'headers' => $headers,
            )
        );
    }

    /**
     * Resolve the status code (or error code) of a response
     *
     * @param array|WP_Error $response
     * @return int|string
     */
    public function remoteStatus($response)
    {
        if (is_wp_error($response)) {
            return $response->get_error_code();
        }

        return wp_remote_retrieve_response_code($response);
    }

    /**
     * Cache key for a form's field list
     *
     * @param string $formId
     * @return string
     */
    private function fieldsCacheKey($formId)
    {
        $bust = (int) get_option('klyp_gftohs_cache_bust', 0);

        return 'klyp_gftohs_fields_' . md5($formId . '|' . $this->accessToken . '|' . $bust . '|' . self::CACHE_SCHEMA);
    }

    /**
     * Invalidate every cached form field list
     *
     * Cached entries are keyed by an incrementing counter rather than deleted
     * individually, so a single option write expires all of them.
     *
     * @return void
     */
    public static function flushCache()
    {
        update_option('klyp_gftohs_cache_bust', (int) get_option('klyp_gftohs_cache_bust', 0) + 1, true);
    }

    /**
     * Flatten the nested field groups returned by the Marketing Forms v3 API
     *
     * @param array $fields
     * @return array
     */
    private function normaliseFields($fields)
    {
        $return = array();

        foreach ((array) $fields as $field) {
            if (! is_array($field) || empty($field['name'])) {
                continue;
            }

            $label = isset($field['label']) && $field['label'] !== '' ? $field['label'] : $field['name'];

            $options = array();

            foreach ((array) rgar($field, 'options') as $option) {
                if (is_array($option) && isset($option['value'])) {
                    $options[] = (string) $option['value'];
                }
            }

            $return[] = (object) array(
                'name'         => (string) $field['name'],
                'label'        => (string) $label,
                'type'         => isset($field['fieldType']) ? (string) $field['fieldType'] : '',
                'objectTypeId' => isset($field['objectTypeId']) ? (string) $field['objectTypeId'] : '',
                'required'     => ! empty($field['required']),
                'options'      => $options,
            );

            // Conditionally displayed fields are nested inside their parent.
            if (! empty($field['dependentFields'])) {
                $return = array_merge($return, $this->normaliseFields($field['dependentFields']));
            }
        }

        return $return;
    }

    /**
     * Read the field list for a Hubspot form
     *
     * Returns an empty array on any failure and records the reason in
     * $this->lastError. It must never halt the request: this runs while the
     * Gravity Forms editor and form settings screens are rendering.
     *
     * @param string $formId
     * @return array
     */
    public function getFormFields($formId)
    {
        $formId          = trim((string) $formId);
        $this->lastError = '';

        if ($formId === '') {
            return array();
        }

        if (! $this->hasCredentials()) {
            $this->lastError = __('No Hubspot Private App access token has been configured.', 'klyp-gf-to-hubspot');
            return array();
        }

        $cacheKey = $this->fieldsCacheKey($formId);
        $fields   = get_transient($cacheKey);

        if (! is_array($fields)) {
            $fields = $this->fetchFormFields($formId);

            if ($this->lastError !== '') {
                return array();
            }

            set_transient($cacheKey, $fields, self::CACHE_TTL);
        }

        return $fields;
    }

    /**
     * List the forms available in the Hubspot portal
     *
     * Used by the settings screen to confirm the credentials work and to show
     * administrators the form GUIDs they would otherwise hunt for in Hubspot.
     * Returns an empty array on failure, with the reason in $this->lastError.
     *
     * @param int $limit
     * @return array Objects with id and name.
     */
    public function listForms($limit = 100)
    {
        $this->lastError = '';

        if (! $this->hasCredentials()) {
            $this->lastError = __('No Hubspot Private App access token has been configured.', 'klyp-gf-to-hubspot');
            return array();
        }

        $cacheKey = 'klyp_gftohs_forms_' . md5($this->accessToken . '|' . (int) get_option('klyp_gftohs_cache_bust', 0) . '|' . self::CACHE_SCHEMA);
        $cached   = get_transient($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->remoteGet('marketing/v3/forms/?limit=' . absint($limit));

        if (is_wp_error($response)) {
            $this->lastError = $response->get_error_message();
            $this->log('Could not reach Hubspot: ' . $this->lastError);
            return array();
        }

        $status = $this->remoteStatus($response);
        $body   = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            $this->lastError = is_array($body) && ! empty($body['message'])
                ? $body['message']
                : sprintf(
                    /* translators: %s: HTTP status code */
                    __('Hubspot returned an unexpected response (HTTP %s).', 'klyp-gf-to-hubspot'),
                    $status
                );

            $this->log(sprintf('Form list failed (HTTP %s): %s', $status, $this->lastError));

            return array();
        }

        $forms = array();

        foreach ((array) rgar($body, 'results') as $form) {
            if (empty($form['id']) || ! empty($form['archived'])) {
                continue;
            }

            $forms[] = (object) array(
                'id'   => (string) $form['id'],
                'name' => isset($form['name']) && $form['name'] !== '' ? (string) $form['name'] : (string) $form['id'],
            );
        }

        usort(
            $forms,
            function ($a, $b) {
                return strcasecmp($a->name, $b->name);
            }
        );

        set_transient($cacheKey, $forms, self::CACHE_TTL);

        return $forms;
    }

    /**
     * Request a form definition from the Marketing Forms v3 API
     *
     * @param string $formId
     * @return array
     */
    private function fetchFormFields($formId)
    {
        $response = $this->remoteGet('marketing/v3/forms/' . rawurlencode($formId));
        $status   = $this->remoteStatus($response);

        if (is_wp_error($response)) {
            $this->lastError = $response->get_error_message();
            $this->log('Could not reach Hubspot: ' . $this->lastError);
            return array();
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200) {
            $message = is_array($body) && ! empty($body['message'])
                ? $body['message']
                : sprintf(
                    /* translators: %s: HTTP status code */
                    __('Hubspot returned an unexpected response (HTTP %s).', 'klyp-gf-to-hubspot'),
                    $status
                );

            $this->lastError = $message;
            $this->log(sprintf('Form %s lookup failed (HTTP %s): %s', $formId, $status, $message));

            return array();
        }

        if (! is_array($body)) {
            $this->lastError = __('Hubspot returned a response that could not be read.', 'klyp-gf-to-hubspot');
            return array();
        }

        $fields = array();

        foreach ((array) rgar($body, 'fieldGroups') as $group) {
            if (empty($group['fields'])) {
                continue;
            }

            $fields = array_merge($fields, $this->normaliseFields($group['fields']));
        }

        return $fields;
    }

    /**
     * Build the Hubspot field payload from the Gravity Forms entry
     *
     * @return array
     */
    private function processData()
    {
        $values   = array();
        $hsFields = $this->getFieldTypeMap();

        foreach ($this->gfFormFields as $field) {
            $property = trim((string) rgobj($field, 'field_gf_to_hs_map'));

            if ($property === '') {
                continue;
            }

            $value  = $this->getFieldValue($field);
            $hsType = isset($hsFields[$property]) ? (string) rgobj($hsFields[$property], 'type') : '';

            // Hubspot checkbox properties are boolean. An unticked box has to
            // be sent as "false" rather than omitted, otherwise a property that
            // is required on the Hubspot form is reported as missing.
            if (in_array($hsType, array('single_checkbox', 'booleancheckbox'), true)) {
                $values[$property] = $this->isTruthy($value) ? 'true' : 'false';
                continue;
            }

            if ($value === '' || $value === null) {
                continue;
            }

            // Keyed by property name so two Gravity Forms fields mapped to the
            // same Hubspot property cannot produce a duplicate-field error.
            $values[$property] = $value;
        }

        // Guarantee the email address is present even when the Gravity Forms
        // email field itself carries no per-field mapping.
        $emailValue = $this->gfEmailField !== '' ? rgar($this->entry, (string) $this->gfEmailField) : '';

        if ($this->hsEmailField !== '' && ! isset($values[$this->hsEmailField]) && is_email($emailValue)) {
            $values[$this->hsEmailField] = $emailValue;
        }

        $data = array();

        foreach ($values as $name => $value) {
            $data[] = array(
                'name'  => $name,
                'value' => $value,
            );
        }

        return $data;
    }

    /**
     * Map Hubspot property names to their field types
     *
     * Used to send values in the shape each property expects. Field lookups
     * are cached, and a failure simply means no type-specific handling is
     * applied rather than a failed submission.
     *
     * @return array
     */
    private function getFieldTypeMap()
    {
        // Built once per submission: processData() and validateData() both
        // need it, and rebuilding would re-read the transient each time.
        if ($this->fieldTypeMap !== null) {
            return $this->fieldTypeMap;
        }

        $map = array();

        if ($this->hasCredentials()) {
            foreach ((array) $this->getFormFields($this->hsFormId) as $hsField) {
                $map[$hsField->name] = $hsField;
            }
        }

        $this->fieldTypeMap = $map;

        return $map;
    }

    /**
     * Hubspot field types whose value must be one of a fixed set of options
     *
     * @return array
     */
    private function enumerationTypes()
    {
        return array('dropdown', 'radio', 'select', 'checkbox', 'multiple_checkboxes');
    }

    /**
     * Check the payload against the Hubspot form definition
     *
     * Hubspot answers a submission carrying an invalid dropdown option with
     * HTTP 200 and then discards it, so the value is checked here instead of
     * letting the submission disappear silently.
     *
     * @param array $data     Payload fields.
     * @param array $hsFields Hubspot field definitions keyed by property name.
     * @return array Error messages, empty when the payload is usable.
     */
    private function validateData($data, $hsFields)
    {
        $errors = array();

        if (empty($hsFields)) {
            return $errors;
        }

        foreach ($data as $entry) {
            $name    = $entry['name'];
            $hsField = isset($hsFields[$name]) ? $hsFields[$name] : null;

            if (! $hsField) {
                $errors[] = sprintf(
                    /* translators: %s: Hubspot property name */
                    __("Error in 'fields.%s'. This field does not exist on the Hubspot form.", 'klyp-gf-to-hubspot'),
                    $name
                );
                continue;
            }

            $options = (array) rgobj($hsField, 'options');

            if (! in_array((string) rgobj($hsField, 'type'), $this->enumerationTypes(), true) || empty($options)) {
                continue;
            }

            // Multi-value answers are sent semicolon delimited.
            foreach (explode(';', (string) $entry['value']) as $value) {
                if ($value === '' || in_array($value, $options, true)) {
                    continue;
                }

                $errors[] = sprintf(
                    /* translators: 1: submitted value, 2: Hubspot field label, 3: Hubspot property name */
                    __("Error in 'fields.%3\$s'. \"%1\$s\" is not one of the accepted options for %2\$s.", 'klyp-gf-to-hubspot'),
                    $value,
                    $hsField->label,
                    $name
                );
            }
        }

        return $errors;
    }

    /**
     * Whether a submitted value represents a ticked checkbox
     *
     * @param string $value
     * @return bool
     */
    private function isTruthy($value)
    {
        $value = strtolower(trim((string) $value));

        return ! in_array($value, array('', '0', 'false', 'no', 'off'), true);
    }

    /**
     * Read a single Gravity Forms field value in the format Hubspot expects
     *
     * @param GF_Field $field
     * @return string
     */
    private function getFieldValue($field)
    {
        $id   = (string) rgobj($field, 'id');
        $type = method_exists($field, 'get_input_type') ? $field->get_input_type() : (string) rgobj($field, 'type');

        switch ($type) {
            case 'checkbox':
                // Each selected choice is stored against its own input ID.
                // Hubspot expects the option values, semicolon delimited.
                $selected = array();

                foreach ((array) rgobj($field, 'inputs') as $input) {
                    $value = rgar($this->entry, (string) rgar($input, 'id'));

                    if ($value !== '' && $value !== null) {
                        $selected[] = $value;
                    }
                }

                return implode(';', $selected);

            case 'multiselect':
                $raw    = rgar($this->entry, $id);
                $values = json_decode((string) $raw, true);

                if (! is_array($values)) {
                    $values = $raw === '' || $raw === null ? array() : explode(',', (string) $raw);
                }

                return implode(';', $this->compactValues($values));

            case 'consent':
                return rgar($this->entry, $id . '.1') ? 'true' : 'false';

            case 'list':
                $values = maybe_unserialize(rgar($this->entry, $id));

                if (! is_array($values)) {
                    return (string) $values;
                }

                $flat = array();

                array_walk_recursive(
                    $values,
                    function ($value) use (&$flat) {
                        if ($value !== '' && $value !== null) {
                            $flat[] = $value;
                        }
                    }
                );

                return implode(';', $flat);
        }

        // Multi-input fields such as Name and Address store one value per
        // input. Without per-input mapping the parts are joined back together.
        $inputs = rgobj($field, 'inputs');

        if (is_array($inputs) && ! empty($inputs)) {
            $parts = array();

            foreach ($inputs as $input) {
                if (rgar($input, 'isHidden')) {
                    continue;
                }

                $value = rgar($this->entry, (string) rgar($input, 'id'));

                if ($value !== '' && $value !== null) {
                    $parts[] = $value;
                }
            }

            return implode(' ', $parts);
        }

        $value = rgar($this->entry, $id);

        if (is_array($value)) {
            return implode(';', $this->compactValues($value));
        }

        return (string) $value;
    }

    /**
     * Drop empty entries and cast the remainder to strings
     *
     * @param array $values
     * @return array
     */
    private function compactValues($values)
    {
        $return = array();

        foreach ((array) $values as $value) {
            if (! is_scalar($value)) {
                continue;
            }

            $value = (string) $value;

            if ($value !== '') {
                $return[] = $value;
            }
        }

        return $return;
    }

    /**
     * Build the Hubspot submission context
     *
     * @return array
     */
    private function processContextData()
    {
        $context = array();

        if (! empty($_COOKIE['hubspotutk'])) {
            $context['hutk'] = sanitize_text_field(wp_unslash($_COOKIE['hubspotutk']));
        }

        /**
         * Whether to send the page URL and title with the submission.
         *
         * Off by default: Hubspot spam-filters a submission whose pageUri is
         * on a domain the portal does not recognise — it answers HTTP 200 and
         * then discards the whole submission without recording it anywhere
         * (verified against the live API). Enable this only on a domain the
         * Hubspot portal tracks.
         *
         * @param bool  $send
         * @param array $entry
         */
        if (apply_filters('klyp_gftohs_send_page_context', false, $this->entry)) {
            // The entry records the page the form was submitted from, which
            // is more reliable than the referer header.
            $sourceUrl = rgar($this->entry, 'source_url');

            if (empty($sourceUrl)) {
                $sourceUrl = wp_get_referer();
            }

            if (! empty($sourceUrl)) {
                $context['pageUri'] = esc_url_raw($sourceUrl);
                $postId             = url_to_postid($sourceUrl);

                if ($postId) {
                    $context['pageName'] = get_the_title($postId);
                }
            }
        }

        $ip = rgar($this->entry, 'ip');

        if (! empty($ip)) {
            $context['ipAddress'] = $ip;
        }

        return $context;
    }

    /**
     * Submit the entry to the Hubspot form
     *
     * @return array
     */
    public function createContact()
    {
        $this->lastError = '';

        if ($this->portalId === '' || $this->hsFormId === '') {
            return array(
                'success' => false,
                'message' => __('The Hubspot portal ID or form ID is missing.', 'klyp-gf-to-hubspot'),
                'errors'  => array(),
            );
        }

        $fields = $this->processData();

        if (empty($fields)) {
            return array(
                'success' => false,
                'message' => __('No Gravity Forms fields are mapped to Hubspot properties.', 'klyp-gf-to-hubspot'),
                'errors'  => array(),
            );
        }

        $invalid = $this->validateData($fields, $this->getFieldTypeMap());

        if (! empty($invalid)) {
            $this->lastError = implode(' ', $invalid);

            // The detail quotes submitted values, so it is verbose-only. The
            // fact of the rejection is always recorded.
            $this->log(
                klypGFToHubspotLogPayloads()
                    ? sprintf('form %s payload rejected before sending: %s', $this->hsFormId, $this->lastError)
                    : sprintf('form %s payload rejected before sending (%d problem(s); enable WP_DEBUG for detail)', $this->hsFormId, count($invalid))
            );

            return array(
                'success' => false,
                'message' => __('Your submission could not be sent to Hubspot.', 'klyp-gf-to-hubspot'),
                'errors'  => $invalid,
            );
        }

        $data    = array('fields' => $fields);
        $context = $this->processContextData();

        if (! empty($context)) {
            $data['context'] = $context;
        }

        /**
         * Filter the payload sent to Hubspot.
         *
         * @param array $data
         * @param array $entry
         */
        $data = apply_filters('klyp_gftohs_submission_payload', $data, $this->entry);

        $url      = self::SUBMIT_BASE . rawurlencode($this->portalId) . '/' . rawurlencode($this->hsFormId);
        $entryId  = rgar($this->entry, 'id') ?: 'unsaved';

        // Every submission is traceable in the log. The payload itself carries
        // the visitor's personal data, so it is only written when verbose
        // logging is on.
        $this->log(
            klypGFToHubspotLogPayloads()
                ? sprintf('entry %s submitting to %s payload: %s', $entryId, $url, wp_json_encode($data))
                : sprintf('entry %s submitting %d field(s) to Hubspot form %s', $entryId, count($fields), $this->hsFormId),
            false
        );

        $response = $this->remotePost($url, $data);
        $status   = $this->remoteStatus($response);

        if (is_wp_error($response)) {
            $this->lastError = $response->get_error_message();
            $this->log(sprintf('entry %s transport error: %s', $entryId, $this->lastError));

            return array(
                'success' => false,
                'message' => $this->lastError,
                'errors'  => array(),
            );
        }

        $this->log(
            sprintf('entry %s response HTTP %s body: %s', $entryId, $status, wp_remote_retrieve_body($response)),
            $status !== 200
        );

        if ($status === 200) {
            return array(
                'success' => true,
                'message' => '',
                'errors'  => array(),
            );
        }

        $body    = json_decode(wp_remote_retrieve_body($response), true);
        $message = is_array($body) && ! empty($body['message'])
            ? $body['message']
            : __('There is something wrong while processing your request. Please try again later.', 'klyp-gf-to-hubspot');

        $errors = array();

        if (is_array($body) && ! empty($body['errors'])) {
            foreach ($body['errors'] as $error) {
                if (is_array($error) && isset($error['message'])) {
                    $errors[] = (string) $error['message'];
                } elseif (is_scalar($error)) {
                    $errors[] = (string) $error;
                }
            }
        }

        $this->lastError = $message;

        return array(
            'success' => false,
            'message' => $message,
            'errors'  => $errors,
        );
    }

    /**
     * Write to the Gravity Forms log
     *
     * @param string $message
     * @return void
     */
    private function log($message, $isError = true)
    {
        klypGFToHubspotLog($message, $isError);
    }
}
