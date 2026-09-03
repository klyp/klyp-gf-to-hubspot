<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Field types that receive the Hubspot mapping setting
 *
 * @return array
 */
function klypGFHSMappableFieldTypes()
{
    return apply_filters(
        'klyp_gftohs_mappable_field_types',
        array(
            'checkbox',
            'consent',
            'date',
            'email',
            'hidden',
            'multiselect',
            'name',
            'number',
            'phone',
            'radio',
            'select',
            'text',
            'textarea',
            'time',
            'website',
        )
    );
}

/**
 * Read the Hubspot fields for a form, once per request
 *
 * The Gravity Forms editor fires gform_field_advanced_settings for every
 * field, so the result is memoised to avoid one API call per field.
 *
 * @param string $hsFormId
 * @return array
 */
function klypGFHSGetCachedFields($hsFormId)
{
    static $cache = array();

    $hsFormId = trim((string) $hsFormId);

    if ($hsFormId === '') {
        return array('fields' => array(), 'error' => '');
    }

    if (! isset($cache[$hsFormId])) {
        $klypHubspot = new klypHubspot();

        $cache[$hsFormId] = array(
            'fields' => (array) $klypHubspot->getFormFields($hsFormId),
            'error'  => $klypHubspot->lastError,
        );
    }

    return $cache[$hsFormId];
}

/**
 * Build the option list for a Hubspot property select
 *
 * @param array  $hsFields
 * @param string $selected
 * @return string
 */
function klypGFHSFieldOptions($hsFields, $selected = '')
{
    $options = sprintf(
        '<option value="">%s</option>',
        esc_html__('— None —', 'klyp-gf-to-hubspot')
    );

    foreach ($hsFields as $hsField) {
        $options .= sprintf(
            '<option value="%s"%s>%s</option>',
            esc_attr($hsField->name),
            selected($selected, $hsField->name, false),
            esc_html(sprintf('%s (%s)', $hsField->label, $hsField->name))
        );
    }

    return $options;
}

/**
 * Add the Hubspot mapping setting to the field advanced settings panel
 *
 * @param int $position
 * @param int $form_id
 * @return void
 */
function klypGFHSFieldMapSetting($position, $form_id)
{
    if ($position !== 0) {
        return;
    }

    $GForm    = GFAPI::get_form($form_id);
    $hsFormId = is_array($GForm) ? trim((string) rgar($GForm, 'klyp-gf-to-hubspot-form-id')) : '';

    // Nothing to map against until a Hubspot form is set in form settings.
    if ($hsFormId === '') {
        return;
    }

    $cached = klypGFHSGetCachedFields($hsFormId);

    echo '<li class="gf_to_hs_setting field_setting">';
    printf(
        '<label for="field_gf_to_hs_map" class="section_label">%s</label>',
        esc_html__('Hubspot field to map', 'klyp-gf-to-hubspot')
    );

    if (! empty($cached['error'])) {
        printf(
            '<p class="gf_to_hs_setting__error">%s</p>',
            esc_html(
                sprintf(
                    /* translators: %s: error message returned by Hubspot */
                    __('Hubspot fields could not be loaded: %s', 'klyp-gf-to-hubspot'),
                    $cached['error']
                )
            )
        );
    } elseif (empty($cached['fields'])) {
        printf(
            '<p class="gf_to_hs_setting__error">%s</p>',
            esc_html__('This Hubspot form has no fields.', 'klyp-gf-to-hubspot')
        );
    }

    printf(
        '<select id="field_gf_to_hs_map" class="field_gf_to_hs_map" onchange="SetFieldProperty(\'field_gf_to_hs_map\', this.value);">%s</select>',
        klypGFHSFieldOptions($cached['fields'])
    );

    echo '</li>';
}
add_action('gform_field_advanced_settings', 'klypGFHSFieldMapSetting', 10, 2);

/**
 * Expose the mapping setting to the supported field types
 *
 * @return void
 */
function klypEditorScript()
{
    $types = wp_json_encode(array_values((array) klypGFHSMappableFieldTypes()));

    if (! $types) {
        return;
    }
    ?>
    <script type="text/javascript">
        (function () {
            var klypHsTypes = <?php echo $types; ?>;

            // fieldSettings is keyed by field type. Guard each key so a type
            // removed by Gravity Forms cannot corrupt the selector string.
            if (typeof fieldSettings === 'object' && fieldSettings !== null) {
                klypHsTypes.forEach(function (type) {
                    if (typeof fieldSettings[type] === 'string' && fieldSettings[type].indexOf('.gf_to_hs_setting') === -1) {
                        fieldSettings[type] += ', .gf_to_hs_setting';
                    }
                });
            }

            jQuery(document).on('gform_load_field_settings', function (event, field) {
                var $select = jQuery('#field_gf_to_hs_map');
                var value = field['field_gf_to_hs_map'] || '';

                if (!$select.length) {
                    return;
                }

                // Keep a mapping that no longer exists on the Hubspot form
                // visible, rather than silently resetting it.
                if (value && !$select.find('option[value="' + value.replace(/"/g, '\\"') + '"]').length) {
                    $select.append(
                        jQuery('<option></option>')
                            .attr('value', value)
                            .text(value + ' (not in Hubspot form)')
                    );
                }

                $select.val(value);
            });
        })();
    </script>
    <?php
}
add_action('gform_editor_js', 'klypEditorScript');

/**
 * Add the Hubspot section to the Gravity Forms form settings screen
 *
 * @param array $fields
 * @param array $form
 * @return array
 */
function klypGFHSAdditionalSettings($fields, $form)
{
    $fields['klyp-gf-to-hubspot'] = array(
        'title'  => __('Hubspot Settings', 'klyp-gf-to-hubspot'),
        'fields' => array(),
    );

    $klypHubspot = new klypHubspot();

    if (! $klypHubspot->hasCredentials() || $klypHubspot->portalId === '') {
        $fields['klyp-gf-to-hubspot']['fields'][] = array(
            'name' => 'klyp-gf-to-hubspot-notice',
            'type' => 'html',
            'html' => sprintf(
                '<div class="alert error">%s</div>',
                sprintf(
                    /* translators: %s: link to the plugin settings screen */
                    esc_html__('Add your Hubspot portal ID and Private App access token in %s before mapping fields.', 'klyp-gf-to-hubspot'),
                    sprintf(
                        '<a href="%s">%s</a>',
                        esc_url(admin_url('options-general.php?page=klyp-gf-to-hubspot')),
                        esc_html__('Settings → Klyp Gravity Form to Hubspot', 'klyp-gf-to-hubspot')
                    )
                )
            ),
        );
    }

    $fields['klyp-gf-to-hubspot']['fields'][] = array(
        'name'          => 'klyp-gf-to-hubspot-form-id',
        'type'          => 'text',
        'label'         => __('Hubspot Form ID', 'klyp-gf-to-hubspot'),
        'default_value' => '',
        'tooltip'       => __('The GUID of the Hubspot form to submit to. Save this setting to load the form fields.', 'klyp-gf-to-hubspot'),
    );

    $hsFormId = trim((string) rgar($form, 'klyp-gf-to-hubspot-form-id'));

    if ($hsFormId === '') {
        return $fields;
    }

    $cached = klypGFHSGetCachedFields($hsFormId);

    if (! empty($cached['error'])) {
        $fields['klyp-gf-to-hubspot']['fields'][] = array(
            'name' => 'klyp-gf-to-hubspot-error',
            'type' => 'html',
            'html' => sprintf(
                '<div class="alert error">%s</div>',
                esc_html(
                    sprintf(
                        /* translators: %s: error message returned by Hubspot */
                        __('Hubspot fields could not be loaded: %s', 'klyp-gf-to-hubspot'),
                        $cached['error']
                    )
                )
            ),
        );

        return $fields;
    }

    // Gravity Forms field holding the email address.
    $gfFieldsChoices = array(
        array(
            'label' => __('— None —', 'klyp-gf-to-hubspot'),
            'value' => '',
        ),
    );

    foreach ((array) rgar($form, 'fields') as $gfField) {
        $label = (string) rgobj($gfField, 'label');

        if ($label === '') {
            continue;
        }

        $gfFieldsChoices[] = array(
            'label' => $label,
            'value' => (string) rgobj($gfField, 'id'),
        );
    }

    $fields['klyp-gf-to-hubspot']['fields'][] = array(
        'name'          => 'klyp-gf-to-hubspot-gf-email-field',
        'type'          => 'select',
        'label'         => __('Email field used in gravity form', 'klyp-gf-to-hubspot'),
        'default_value' => '',
        'tooltip'       => __('Used to guarantee the email address reaches Hubspot even when the field carries no mapping of its own.', 'klyp-gf-to-hubspot'),
        'choices'       => $gfFieldsChoices,
    );

    // Hubspot property holding the email address.
    $hsFieldsChoices = array(
        array(
            'label' => __('— None —', 'klyp-gf-to-hubspot'),
            'value' => '',
        ),
    );

    foreach ($cached['fields'] as $hsField) {
        $hsFieldsChoices[] = array(
            'label' => sprintf('%s (%s)', $hsField->label, $hsField->name),
            'value' => $hsField->name,
        );
    }

    $fields['klyp-gf-to-hubspot']['fields'][] = array(
        'name'          => 'klyp-gf-to-hubspot-email-field',
        'type'          => 'select',
        'label'         => __('Email field used in Hubspot', 'klyp-gf-to-hubspot'),
        'default_value' => '',
        'tooltip'       => __('The Hubspot property the email address is written to.', 'klyp-gf-to-hubspot'),
        'choices'       => $hsFieldsChoices,
    );

    // Conversion page. Auto-detected when left unset.
    $detected = klypGFHSDetectConversionPageField($cached['fields']);

    $conversionChoices = array(
        array(
            'label' => $detected !== ''
                /* translators: %s: Hubspot property name */
                ? sprintf(__('Detected automatically (%s)', 'klyp-gf-to-hubspot'), $detected)
                : __('— Not recorded —', 'klyp-gf-to-hubspot'),
            'value' => '',
        ),
    );

    $conversionChoices = array_merge($conversionChoices, array_slice($hsFieldsChoices, 1));

    $fields['klyp-gf-to-hubspot']['fields'][] = array(
        'name'          => 'klyp-gf-to-hubspot-conversion-page-field',
        'type'          => 'select',
        'label'         => __('Conversion page field in Hubspot', 'klyp-gf-to-hubspot'),
        'default_value' => '',
        'tooltip'       => __('The Hubspot property that records which page the form was submitted from. The field must exist on the Hubspot form — Hubspot accepts a submission carrying a field the form does not declare, then drops that value. Leave unset to detect a conventionally named field automatically.', 'klyp-gf-to-hubspot'),
        'choices'       => $conversionChoices,
    );

    if ($detected === '' && trim((string) rgar($form, 'klyp-gf-to-hubspot-conversion-page-field')) === '') {
        $fields['klyp-gf-to-hubspot']['fields'][] = array(
            'name' => 'klyp-gf-to-hubspot-conversion-page-notice',
            'type' => 'html',
            'html' => sprintf(
                '<div class="alert info">%s</div>',
                sprintf(
                    /* translators: %s: comma separated list of property names */
                    esc_html__('This Hubspot form has no field for the conversion page. Add a single-line text field to the form in Hubspot — name it one of %s to have it picked up automatically — then clear the cached fields on the plugin settings screen.', 'klyp-gf-to-hubspot'),
                    esc_html(implode(', ', array_slice(klypHubspot::conversionPageCandidates(), 0, 4)))
                )
            ),
        );
    }

    return $fields;
}

/**
 * Find a conventionally named conversion page field on the Hubspot form
 *
 * @param array $hsFields
 * @return string
 */
function klypGFHSDetectConversionPageField($hsFields)
{
    $names = array();

    foreach ((array) $hsFields as $hsField) {
        $names[(string) rgobj($hsField, 'name')] = true;
    }

    foreach (klypHubspot::conversionPageCandidates() as $candidate) {
        if (isset($names[$candidate])) {
            return (string) $candidate;
        }
    }

    return '';
}
add_filter('gform_form_settings_fields', 'klypGFHSAdditionalSettings', 10, 2);

/**
 * Drop cached Hubspot fields when a form's Hubspot ID changes
 *
 * @param array $form
 * @return array
 */
function klypGFHSFlushCacheOnSave($form)
{
    klypHubspot::flushCache();

    return $form;
}
add_filter('gform_pre_form_settings_save', 'klypGFHSFlushCacheOnSave', 10, 1);
