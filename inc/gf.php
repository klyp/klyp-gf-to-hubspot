<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

function klypHsGFCatchSubmission($form)
{
    if (empty($form['form']['id'])) {
        return;
    }

    // start hubspot
    $hubspot = new klypHubspot();
    $hubspot->hsFormId      = $form['form']['klyp-gf-to-hubspot-form-id'];
    $hubspot->gfFormFields  = $form['form']['fields'];
    $hubspot->postedData    = GFFormsModel::get_current_lead();
    $hubspot->apiKey        = get_option('klyp_gftohs_api_key');
    $hubspot->portalId      = get_option('klyp_gftohs_portal_id');
    $hubspot->gfEmailField  = $form['form']['klyp-gf-to-hubspot-gf-email-field'];
    $hubspot->hsEmailField  = $form['form']['klyp-gf-to-hubspot-email-field'];

    if (empty($hubspot->hsFormId) && empty($hubspot->hsEmailField)) {
        return;
    }

    // create contact
    $hubspotReturn = $hubspot->createContact();

    if (isset($hubspotReturn['success']) && $hubspotReturn['success'] === false) {
        if ($hubspotReturn['errors']) {
            // set invalid form
            $form['is_valid'] = false;
            foreach ($hubspotReturn['errors'] as $key => $value) {
                $hsErrorKey = klypGFHsGetStringBetween($value->message, "fields.", "'");

                foreach ($form['form']['fields'] as &$field) {
                    if ($field->field_gf_to_hs_map == $hsErrorKey) {
                        $field->failed_validation  = true;
                        $field->validation_message = $value->message;
                    }
                }
            }
        }
    }

    return $form;
}
add_action('gform_validation', 'klypHsGFCatchSubmission', 10, 2);

/**
 * Get string between
 * @param string
 * @param string
 * @param string
 * @return string
 */
function klypGFHsGetStringBetween($string, $start, $end)
{
    if (strpos($string, $start)) {
        $startCharCount = strpos($string, $start) + strlen($start);
        $firstSubStr = substr($string, $startCharCount, strlen($string));
        $endCharCount = strpos($firstSubStr, $end);
        if ($endCharCount == 0) {
            $endCharCount = strlen($firstSubStr);
        }
        return substr($firstSubStr, 0, $endCharCount);
    } else {
        return '';
    }
}
