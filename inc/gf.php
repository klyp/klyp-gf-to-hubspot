<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Send the submission to Hubspot and block the form if Hubspot rejects it
 *
 * This runs on gform_validation so a Hubspot failure stops the submission and
 * is shown to the visitor. Nothing is saved when Hubspot rejects the data: the
 * entry, notifications and confirmation all depend on validation passing.
 *
 * @param array $validationResult
 * @return array
 */
function klypHsGFValidate($validationResult)
{
    $form = rgar($validationResult, 'form');

    if (! is_array($form)) {
        return $validationResult;
    }

    // Gravity Forms already found a problem; there is nothing to send yet.
    if (empty($validationResult['is_valid'])) {
        return $validationResult;
    }

    $hsFormId = trim((string) rgar($form, 'klyp-gf-to-hubspot-form-id'));

    if ($hsFormId === '') {
        return $validationResult;
    }

    // Multi-page forms validate once per page. Only the final page carries the
    // complete submission.
    if (class_exists('GFFormDisplay') && ! GFFormDisplay::is_last_page($form)) {
        return $validationResult;
    }

    $formId = (string) rgar($form, 'id');

    $lead = GFFormsModel::get_current_lead($form);

    if (! is_array($lead)) {
        $lead = GFFormsModel::create_lead($form);
    }

    // Guard against the filter running twice for the same submission without
    // blocking a genuinely different one — GFAPI::submit_form() can be called
    // repeatedly in a single process, and silently skipping those would be the
    // very failure mode this plugin exists to avoid.
    static $done = array();

    $fingerprint = $formId . ':' . md5((string) wp_json_encode($lead));

    if (isset($done[$fingerprint])) {
        return $validationResult;
    }

    $done[$fingerprint] = true;

    $hubspot = new klypHubspot();

    if ($hubspot->portalId === '') {
        return klypHsGFFailValidation($validationResult, array(), 'no Hubspot portal ID configured');
    }

    $hubspot->hsFormId     = $hsFormId;
    $hubspot->gfFormFields = (array) rgar($form, 'fields');
    $hubspot->entry        = is_array($lead) ? $lead : array();
    $hubspot->gfEmailField = (string) rgar($form, 'klyp-gf-to-hubspot-gf-email-field');
    $hubspot->hsEmailField = (string) rgar($form, 'klyp-gf-to-hubspot-email-field');
    $hubspot->hsConversionPageField = (string) rgar($form, 'klyp-gf-to-hubspot-conversion-page-field');

    $result = $hubspot->createContact();

    if (! empty($result['success'])) {
        klypGFToHubspotLog(sprintf('form %s sent to Hubspot form %s.', $formId, $hsFormId), false);
        klypHsGFSubmissionSucceeded($formId, true);

        return $validationResult;
    }

    $messages = array_filter(array_merge((array) rgar($result, 'errors'), array((string) rgar($result, 'message'))));

    // Rejection messages quote the values the visitor submitted, so the log
    // entry only carries them when verbose logging is on. The failure itself
    // is always recorded.
    $logEntry = klypGFToHubspotLogPayloads()
        ? implode(' ', $messages)
        : sprintf('%d problem(s) reported; enable WP_DEBUG for detail', count($messages));

    return klypHsGFFailValidation($validationResult, $messages, $logEntry);
}
add_filter('gform_validation', 'klypHsGFValidate', 20, 1);

/**
 * Mark the submission invalid and attach the Hubspot error to the form
 *
 * @param array  $validationResult
 * @param array  $messages Individual Hubspot errors, used to flag fields.
 * @param string $logEntry Message written to the Gravity Forms log.
 * @return array
 */
function klypHsGFFailValidation($validationResult, $messages, $logEntry)
{
    $form   = $validationResult['form'];
    $formId = (string) rgar($form, 'id');

    klypGFToHubspotLog(sprintf('form %s was not sent to Hubspot: %s', $formId, $logEntry));

    $validationResult['is_valid'] = false;
    $flagged                      = false;

    // Hubspot names the property it rejected, so the error can be shown on the
    // Gravity Forms field mapped to it.
    foreach ($messages as $hsMessage) {
        $property = klypHsGFErrorProperty($hsMessage);

        if ($property === '') {
            continue;
        }

        foreach ((array) rgar($form, 'fields') as $field) {
            if ((string) rgobj($field, 'field_gf_to_hs_map') !== $property) {
                continue;
            }

            $field->failed_validation  = true;
            $field->validation_message = klypHsGFReadableError($hsMessage);
            $flagged                   = true;
        }
    }

    $validationResult['form'] = $form;

    /**
     * Filter the message shown above the form when the failure cannot be
     * attributed to a single field. Hubspot's own wording is written for
     * developers, so it is logged rather than shown to the visitor.
     *
     * @param string $message
     * @param array  $messages
     * @param array  $form
     */
    $message = apply_filters(
        'klyp_gftohs_error_message',
        __('Sorry, your submission could not be completed. Please try again, or contact us if the problem continues.', 'klyp-gf-to-hubspot'),
        $messages,
        $form
    );

    // Nothing could be pinned to a field, so the message is shown above the
    // form instead of leaving the visitor with a summary pointing nowhere.
    klypHsGFFormMessage($formId, $flagged ? '' : $message);

    return $validationResult;
}

/**
 * Extract the Hubspot property name from an error message
 *
 * Hubspot phrases these as: Error in 'fields.some_property'. ...
 *
 * @param string $message
 * @return string
 */
function klypHsGFErrorProperty($message)
{
    if (preg_match('/fields\.([A-Za-z0-9_\-]+)/', (string) $message, $matches)) {
        return $matches[1];
    }

    return '';
}

/**
 * Strip the developer facing prefix from a Hubspot error
 *
 * @param string $message
 * @return string
 */
function klypHsGFReadableError($message)
{
    $readable = trim(preg_replace("/^Error in 'fields\.[A-Za-z0-9_\-]+'\.\s*/", '', (string) $message));

    return $readable === '' ? (string) $message : $readable;
}

/**
 * Store and read the form level Hubspot error
 *
 * @param string      $formId
 * @param string|null $message Pass a string to store, omit to read.
 * @return string
 */
function klypHsGFFormMessage($formId, $message = null)
{
    static $messages = array();

    if ($message !== null) {
        $messages[$formId] = $message;
    }

    return isset($messages[$formId]) ? $messages[$formId] : '';
}

/**
 * Show the Hubspot error above the form
 *
 * @param string $markup
 * @param array  $form
 * @return string
 */
function klypHsGFValidationMessage($markup, $form)
{
    $message = klypHsGFFormMessage((string) rgar($form, 'id'));

    if ($message === '') {
        return $markup;
    }

    return sprintf(
        '<h2 class="gform_submission_error hide_summary"><span class="gform-icon gform-icon--circle-error"></span>%s</h2>',
        esc_html($message)
    );
}
add_filter('gform_validation_message', 'klypHsGFValidationMessage', 10, 2);

/**
 * Track whether a given form reached Hubspot in this request
 *
 * Keyed by form ID: a page can host more than one form, and a note saying the
 * entry reached Hubspot must not be attached to a form that never went.
 *
 * @param string    $formId
 * @param bool|null $succeeded Pass a bool to store, omit to read.
 * @return bool
 */
function klypHsGFSubmissionSucceeded($formId, $succeeded = null)
{
    static $state = array();

    $formId = (string) $formId;

    if ($succeeded !== null) {
        $state[$formId] = (bool) $succeeded;
    }

    return ! empty($state[$formId]);
}

/**
 * Record the Hubspot delivery against the saved entry
 *
 * Only successful submissions get this far: a Hubspot failure fails validation,
 * so no entry is created.
 *
 * @param array $entry
 * @param array $form
 * @return void
 */
function klypHsGFAfterSubmission($entry, $form)
{
    if (! klypHsGFSubmissionSucceeded(rgar($form, 'id'))) {
        return;
    }

    $entryId = rgar($entry, 'id');

    if (empty($entryId) || ! class_exists('GFAPI')) {
        return;
    }

    GFAPI::add_note($entryId, 0, 'Hubspot', __('Sent to Hubspot.', 'klyp-gf-to-hubspot'), 'klyp-gf-to-hubspot', 'success');
}
add_action('gform_after_submission', 'klypHsGFAfterSubmission', 10, 2);
