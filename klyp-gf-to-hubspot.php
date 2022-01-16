<?php
/**
 * Plugin Name: Klyp Gravity Form to Hubspot
 * Plugin URI: https://github.com/klyp/klyp-gf-to-hubspot
 * Description: This plugin allows you to map Gravity Forms fields to Hubspot form fields.
 * Version: 1.0.2
 * Author: Klyp
 * Author URI: https://klyp.co
 * License: GPL2
 */

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

if (! class_exists('klypGFToHubspot')) {

    class klypGFToHubspot
    {
        /**
         * Construct
         * @return void
         */
        public function __construct()
        {
            // Settings
            require_once(sprintf("%s/inc/settings.php", dirname(__FILE__)));
            require_once(sprintf("%s/inc/gf.php", dirname(__FILE__)));
            require_once(sprintf("%s/inc/hubspot.php", dirname(__FILE__)));
            require_once(sprintf("%s/inc/hubspot-api.php", dirname(__FILE__)));
        }

        /**
         * Hook into the WordPress activate hook
         * @return void
         */
        public static function activate()
        {
        }

        /**
         * Hook into the WordPress deactivate hook
         * @return void
         */
        public static function deactivate()
        {
        }
    }
}

if (class_exists('klypGFToHubspot')) {
    // Installation and uninstallation hooks
    register_activation_hook(__FILE__, array('klypGFToHubspot', 'activate'));
    register_deactivation_hook(__FILE__, array('klypGFToHubspot', 'deactivate'));

    // instantiate the plugin class
    $plugin = new klypGFToHubspot();
}
