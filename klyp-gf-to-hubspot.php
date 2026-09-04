<?php
/**
 * Plugin Name: Klyp Gravity Form to Hubspot
 * Plugin URI: https://github.com/klyp/klyp-gf-to-hubspot
 * Description: This plugin allows you to map Gravity Forms fields to Hubspot form fields.
 * Version: 2.1.0
 * Author: Klyp
 * Author URI: https://klyp.co
 * License: GPL2
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Text Domain: klyp-gf-to-hubspot
 */

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

define('KLYP_GFTOHS_VERSION', '2.0.0');
define('KLYP_GFTOHS_PATH', plugin_dir_path(__FILE__));
define('KLYP_GFTOHS_MIN_GF', '2.5');

if (! class_exists('klypGFToHubspot')) {

    class klypGFToHubspot
    {
        /**
         * Construct
         *
         * Nothing is loaded here directly: Gravity Forms must be present and
         * recent enough before any of our hooks are registered, and that can
         * only be determined once every plugin has been included.
         *
         * @return void
         */
        public function __construct()
        {
            add_action('plugins_loaded', array($this, 'bootstrap'), 20);
        }

        /**
         * Load the plugin once Gravity Forms is known to be available
         *
         * @return void
         */
        public function bootstrap()
        {
            if (! $this->hasSupportedGravityForms()) {
                add_action('admin_notices', array($this, 'gravityFormsNotice'));
                return;
            }

            // The API client is required by the admin UI, so it loads first.
            require_once KLYP_GFTOHS_PATH . 'inc/hubspot-api.php';
            require_once KLYP_GFTOHS_PATH . 'inc/settings.php';
            require_once KLYP_GFTOHS_PATH . 'inc/hubspot.php';
            require_once KLYP_GFTOHS_PATH . 'inc/gf.php';
        }

        /**
         * Check Gravity Forms is active and new enough to expose the hooks we use
         *
         * @return bool
         */
        public function hasSupportedGravityForms()
        {
            if (! class_exists('GFForms') || ! class_exists('GFAPI') || ! class_exists('GFCommon')) {
                return false;
            }

            // GFForms::$version is the running plugin version. The database
            // version lags behind it while an upgrade migration is pending, so
            // it must not be used to gate feature detection.
            return version_compare(GFForms::$version, KLYP_GFTOHS_MIN_GF, '>=');
        }

        /**
         * Warn administrators when the Gravity Forms requirement is not met
         *
         * @return void
         */
        public function gravityFormsNotice()
        {
            if (! current_user_can('activate_plugins')) {
                return;
            }

            printf(
                '<div class="notice notice-error"><p>%s</p></div>',
                esc_html(
                    sprintf(
                        /* translators: %s: minimum supported Gravity Forms version */
                        __('Klyp Gravity Form to Hubspot requires Gravity Forms %s or newer to be installed and active.', 'klyp-gf-to-hubspot'),
                        KLYP_GFTOHS_MIN_GF
                    )
                )
            );
        }

        /**
         * Hook into the WordPress activate hook
         *
         * @return void
         */
        public static function activate()
        {
        }

        /**
         * Hook into the WordPress deactivate hook
         *
         * @return void
         */
        public static function deactivate()
        {
            delete_option('klyp_gftohs_cache_bust');
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
