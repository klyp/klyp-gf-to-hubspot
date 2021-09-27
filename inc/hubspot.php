<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Add custom hubspot settings for GF Fields
 * @param array
 * @param int
 * @return array
 */
function klypGFHSFieldMapSetting($position, $form_id)
{
    // get GF form object
    $GForm = GFAPI::get_form($form_id);

    // if hubspot form isn't set or empty then do nothing
    if (! isset($GForm['klyp-gf-to-hubspot-form-id']) || empty($GForm['klyp-gf-to-hubspot-form-id'])) {
        return;
    }

    // start klypHubspot instance
    $klypHubspot    = new klypHubspot();
    $hsFields       = $klypHubspot->getFormFields($GForm['klyp-gf-to-hubspot-form-id']);

    if ($position == 50) {
        $return = '
            <li class="gf_to_hs_setting field_setting">
                <label for="field_gf_to_hs_map" class="section_label">' . __('Hubspot field to map') . '</label>
                <select id="field_gf_to_hs_map" class="field_gf_to_hs_map" onchange="SetFieldProperty(\'field_gf_to_hs_map\', this.value);">
                    <option value="" disabled>Select hubspot field to map</option>';
                    foreach ($hsFields as $key => $hsField) {
                        $return .= '<option value="' . $hsField->name . '">' . $hsField->label . ' (' . $hsField->name . ')</option>';
                    }
        $return .= '
                </select>
            </li>';
    }

    echo $return;
}
add_action('gform_field_advanced_settings', 'klypGFHSFieldMapSetting', 10, 2);

/**
 * Add support to all fields
 * @return void
 */
function klypEditorScript()
{
    $return = '
        <script type="text/javascript">
            fieldSettings.text += ", .gf_to_hs_setting";
            fieldSettings.textarea += ", .gf_to_hs_setting";
            fieldSettings.email += ", .gf_to_hs_setting";
            fieldSettings.phone += ", .gf_to_hs_setting";
            fieldSettings.number += ", .gf_to_hs_setting";
            fieldSettings.select += ", .gf_to_hs_setting";

            jQuery(document).on("gform_load_field_settings", function(event, field, form) {
                jQuery("#field_gf_to_hs_map").val(field["field_gf_to_hs_map"]);
            });
        </script>';

        echo $return;
}
add_action('gform_editor_js', 'klypEditorScript');

/**
 * Add custom hubspot settings for GF Form
 * @param array
 * @param object
 * @return array
 */
function klypGFHSAdditionalSettings($settings, $form)
{
    $settings[__('Hubspot Settings')]['klyp-gf-to-hubspot-form-id'] = '
    <tr>
        <th>' . __('Hubspot Form ID') . '</th>
        <td>
            <input id="klyp-gf-to-hubspot-form-id" name="klyp-gf-to-hubspot-form-id" type="text" value="' . rgar($form, 'klyp-gf-to-hubspot-form-id') . '" class="fieldwidth-3">
        </td>
    </tr>';

    if (rgar($form, 'klyp-gf-to-hubspot-form-id') == '' || empty(rgar($form, 'klyp-gf-to-hubspot-form-id'))) {
        return $settings;
    }

    // generate gf fields
    $gfFields       = $form['fields'];
    $gfEmailField   = '
        <tr>
            <th>' . __('Email field used in gravity form') . '</th>
            <td>
                <select id="klyp-gf-to-hubspot-gf-email-field" name="klyp-gf-to-hubspot-gf-email-field" class="fieldwidth-3">
                    <option value="" disabled>Select email field</option>';
                    foreach ($gfFields as $key => $gfField) {
                        if (empty($gfField->label)) { continue; }
                        $gfEmailField .= '<option value="' . $gfField->id . '" ' . (rgar($form, 'klyp-gf-to-hubspot-gf-email-field') == $gfField->id ? 'selected="selected"' : '') . '>' . $gfField->label . '</option>';
                    }
            $gfEmailField .= '
                </select>
            </td>
        </tr>';

    $settings[__('Hubspot Settings')]['klyp-gf-to-hubspot-form-id'] .= $gfEmailField;

    // generate hubspot fields
    $klypHubspot    = new klypHubspot();
    $hsFields       = $klypHubspot->getFormFields(rgar($form, 'klyp-gf-to-hubspot-form-id'));

    $hubspotEmailField = '
    <tr>
        <th>'.__('Email field used in hubspot form').'</th>
        <td>
            <select id="klyp-gf-to-hubspot-email-field" name="klyp-gf-to-hubspot-email-field" class="fieldwidth-3">
                <option value="" disabled>Select Hubspot email field</option>';
                foreach ($hsFields as $key => $hsField) {
                    $hubspotEmailField .= '<option value="' . $hsField->name . '" ' . (rgar($form, 'klyp-gf-to-hubspot-email-field') == $hsField->name ? 'selected="selected"' : '') . '>' . $hsField->label . ' (' . $hsField->name . ')</option>';
                }
        $hubspotEmailField .= '
            </select>
        </td>
    </tr>';

    $settings[__('Hubspot Settings')]['klyp-gf-to-hubspot-form-id'] .= $hubspotEmailField;

    return $settings;
}
add_filter('gform_form_settings', 'klypGFHSAdditionalSettings', 10, 2);

/**
 * Save settings on submit
 * @param array
 * @return array
 */
function klypGFHSAdditionalSettingsSubmit($form)
{
    $form['klyp-gf-to-hubspot-form-id']         = rgpost('klyp-gf-to-hubspot-form-id');
    $form['klyp-gf-to-hubspot-gf-email-field']  = rgpost('klyp-gf-to-hubspot-gf-email-field');
    $form['klyp-gf-to-hubspot-email-field']     = rgpost('klyp-gf-to-hubspot-email-field');
    return $form;
}
add_filter('gform_pre_form_settings_save', 'klypGFHSAdditionalSettingsSubmit', 10, 2);
