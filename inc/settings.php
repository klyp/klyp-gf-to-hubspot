<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

/**
 * Create menu under settings
 *
 * @return void
 */
function klypGFToHubspotMenu()
{
    add_options_page('Klyp Gravity Form to Hubspot', 'Klyp Gravity Form to Hubspot', 'manage_options', 'klyp-gf-to-hubspot', 'klypGFToHubspotSettings');
}
add_action('admin_menu', 'klypGFToHubspotMenu');

/**
 * Create the settings page
 *
 * @return void
 */
function klypGFToHubspotSettings()
{
    require_once(sprintf("%s/settings-page.php", dirname(__FILE__)));
}

/**
 * Register Plugin settings
 *
 * @return void
 */
function klypGFToHubspotRegisterSettings()
{
    //register our settings
    define('KlypGFToHusbspot', 'klyp-gf-to-hubspot');
    register_setting(KlypGFToHubspot, 'klyp_gftohs_api_key');
    register_setting(KlypGFToHubspot, 'klyp_gftohs_portal_id');
    register_setting(KlypGFToHubspot, 'klyp_gftohs_base_url');
}
add_action('admin_init', 'klypGFToHubspotRegisterSettings');

/**
 * Sanitize input
 *
 * @param string/array
 * @return string/array
 */
function klypGFToHubspotSanitizeInput($input)
{
    if (is_array($input)) {
        $return = array ();

        foreach ($input as $key => $value) {
            $return[$key] = is_array($value) ? $value : sanitize_text_field($value);
        }

        return $return;
    } else {
        return sanitize_text_field($input);
    }
}

/**
 * Load JS
 *
 * @param string
 * @return void
 */
function klypGFToHubspotLoadJS($hook)
{
    // only fire up when we are editing contact
    if ($hook == 'toplevel_page_gf_edit_forms') {
        wp_enqueue_script('klyp-gf-to-hubspot-js', plugins_url('/assets/js/main.js', dirname(__FILE__)));
    }
}
add_action('admin_enqueue_scripts', 'klypGFToHubspotLoadJS');
