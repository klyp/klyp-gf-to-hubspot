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
    add_options_page(
        __('Klyp Gravity Form to Hubspot', 'klyp-gf-to-hubspot'),
        __('Klyp Gravity Form to Hubspot', 'klyp-gf-to-hubspot'),
        'manage_options',
        'klyp-gf-to-hubspot',
        'klypGFToHubspotSettings'
    );
}
add_action('admin_menu', 'klypGFToHubspotMenu');

/**
 * Create the settings page
 *
 * @return void
 */
function klypGFToHubspotSettings()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to access this page.', 'klyp-gf-to-hubspot'));
    }

    require KLYP_GFTOHS_PATH . 'inc/settings-page.php';
}

/**
 * Register Plugin settings
 *
 * @return void
 */
function klypGFToHubspotRegisterSettings()
{
    register_setting(
        'KlypGFToHubspot',
        'klyp_gftohs_portal_id',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'klypGFToHubspotSanitizePortalId',
            'default'           => '',
        )
    );

    register_setting(
        'KlypGFToHubspot',
        'klyp_gftohs_access_token',
        array(
            'type'              => 'string',
            'sanitize_callback' => 'klypGFToHubspotSanitizeToken',
            'default'           => '',
        )
    );
}
add_action('admin_init', 'klypGFToHubspotRegisterSettings');

/**
 * Sanitize the Hubspot portal ID
 *
 * @param string $input
 * @return string
 */
function klypGFToHubspotSanitizePortalId($input)
{
    return preg_replace('/[^0-9]/', '', (string) $input);
}

/**
 * Render a token as a recognisable but unusable hint
 *
 * Shows enough for an administrator to tell which token is stored without
 * putting the credential itself in the page.
 *
 * @param string $token
 * @return string
 */
function klypGFToHubspotMaskToken($token)
{
    $token = (string) $token;

    if (strlen($token) < 12) {
        return str_repeat('•', max(strlen($token), 4));
    }

    return substr($token, 0, 4) . str_repeat('•', 8) . substr($token, -4);
}

/**
 * Sanitize the Hubspot Private App access token
 *
 * The stored token is never rendered back into the settings screen, so the
 * field arrives empty unless the administrator typed a new one. An empty
 * submission therefore means "leave the stored token alone"; clearing it is an
 * explicit, separate action.
 *
 * @param string $input
 * @return string
 */
function klypGFToHubspotSanitizeToken($input)
{
    $existing = (string) get_option('klyp_gftohs_access_token', '');

    // options.php has already verified the nonce for this settings group.
    if (! empty($_POST['klyp_gftohs_clear_token'])) {
        return '';
    }

    $input = trim(sanitize_text_field((string) $input));

    return $input === '' ? $existing : $input;
}

/**
 * Expire cached field lists when the token changes
 *
 * Cache keys incorporate the token, so a new token must not read entries
 * fetched with the old one. This lives on the option hooks rather than in the
 * sanitize callback, which should stay free of side effects.
 *
 * @return void
 */
function klypGFToHubspotTokenChanged()
{
    klypHubspot::flushCache();
}
add_action('add_option_klyp_gftohs_access_token', 'klypGFToHubspotTokenChanged');
add_action('update_option_klyp_gftohs_access_token', 'klypGFToHubspotTokenChanged');

/**
 * Load the settings screen assets
 *
 * Registered against the settings page hook only, so nothing is added to the
 * rest of the admin.
 *
 * @param string $hook
 * @return void
 */
function klypGFToHubspotAdminAssets($hook)
{
    if ($hook !== 'settings_page_klyp-gf-to-hubspot') {
        return;
    }

    wp_register_style('klyp-gftohs-admin', false, array(), KLYP_GFTOHS_VERSION);
    wp_enqueue_style('klyp-gftohs-admin');
    wp_add_inline_style('klyp-gftohs-admin', klypGFToHubspotAdminCss());

    wp_register_script('klyp-gftohs-admin', false, array('jquery'), KLYP_GFTOHS_VERSION, true);
    wp_enqueue_script('klyp-gftohs-admin');
    wp_add_inline_script('klyp-gftohs-admin', klypGFToHubspotAdminJs());

    wp_localize_script(
        'klyp-gftohs-admin',
        'klypGfToHs',
        array(
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('klyp_gftohs_test_connection'),
            'configured' => get_option('klyp_gftohs_portal_id', '') !== '' && get_option('klyp_gftohs_access_token', '') !== '',
            'i18n'       => array(
                'checking' => __('Checking connection to Hubspot…', 'klyp-gf-to-hubspot'),
                'failed'   => __('Could not reach Hubspot. Check the connection and try again.', 'klyp-gf-to-hubspot'),
                'copy'     => __('Copy ID', 'klyp-gf-to-hubspot'),
                'copied'   => __('Copied', 'klyp-gf-to-hubspot'),
                'noForms'  => __('No forms found in this Hubspot account.', 'klyp-gf-to-hubspot'),
                'unavail'  => __('Available once your account is connected.', 'klyp-gf-to-hubspot'),
            ),
        )
    );
}
add_action('admin_enqueue_scripts', 'klypGFToHubspotAdminAssets');

/**
 * Settings screen styles
 *
 * @return string
 */
function klypGFToHubspotAdminCss()
{
    return '
    .klyp-gftohs__lede { max-width: 46em; color: #50575e; margin: .4em 0 1.2em; }
    .klyp-gftohs-status { display: flex; align-items: center; gap: .6em; padding: .85em 1em; margin: 0 0 1.4em;
        background: #fff; border: 1px solid #c3c4c7; border-left-width: 4px; border-radius: 2px; max-width: 100%; }
    .klyp-gftohs-status__dot { width: 10px; height: 10px; border-radius: 50%; flex: 0 0 10px; background: #dba617; }
    .klyp-gftohs-status__text { flex: 1 1 auto; }
    .klyp-gftohs-status--checking { border-left-color: #dba617; }
    .klyp-gftohs-status--checking .klyp-gftohs-status__dot { animation: klypGftohsPulse 1.1s ease-in-out infinite; }
    .klyp-gftohs-status--connected { border-left-color: #00a32a; }
    .klyp-gftohs-status--connected .klyp-gftohs-status__dot { background: #00a32a; }
    .klyp-gftohs-status--error { border-left-color: #d63638; }
    .klyp-gftohs-status--error .klyp-gftohs-status__dot { background: #d63638; }
    .klyp-gftohs-status--unconfigured { border-left-color: #dba617; }
    @keyframes klypGftohsPulse { 0%,100% { opacity: 1; } 50% { opacity: .3; } }
    @media (prefers-reduced-motion: reduce) { .klyp-gftohs-status--checking .klyp-gftohs-status__dot { animation: none; } }
    .klyp-gftohs__columns { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
    .klyp-gftohs__main { flex: 1 1 520px; min-width: 0; }
    .klyp-gftohs__side { flex: 0 1 320px; min-width: 0; }
    .klyp-gftohs-card.card { max-width: none; margin: 0 0 20px; padding: .6em 1.4em 1.2em; }
    .klyp-gftohs-card .form-table th { width: 150px; }
    .klyp-gftohs-token-stored { display: flex; align-items: center; gap: .35em; }
    .klyp-gftohs-token-stored .dashicons { font-size: 16px; width: 16px; height: 16px; color: #50575e; }
    .klyp-gftohs-table__num { text-align: right; white-space: nowrap; }
    .klyp-gftohs-warn { color: #b32d2e; white-space: nowrap; }
    .klyp-gftohs-warn .dashicons { font-size: 16px; width: 16px; height: 16px; vertical-align: text-bottom; }
    .klyp-gftohs-guid { font-size: 11px; word-break: break-all; }
    .klyp-gftohs-muted { color: #646970; }
    .klyp-gftohs-form-list { max-height: 340px; overflow-y: auto; }
    .klyp-gftohs-form-list ul { margin: 0; }
    .klyp-gftohs-form-list li { display: flex; align-items: center; justify-content: space-between; gap: .5em;
        padding: .5em 0; margin: 0; border-bottom: 1px solid #f0f0f1; }
    .klyp-gftohs-form-list li:last-child { border-bottom: 0; }
    .klyp-gftohs-form-list__name { font-weight: 600; display: block; }
    .klyp-gftohs-form-list__id { font-size: 11px; color: #646970; word-break: break-all; }
    @media screen and (max-width: 782px) { .klyp-gftohs__side { flex-basis: 100%; } }
    ';
}

/**
 * Settings screen behaviour
 *
 * @return string
 */
function klypGFToHubspotAdminJs()
{
    return <<<'JS'
(function ($) {
    'use strict';

    var $status, $list;

    function setStatus(state, message) {
        $status
            .removeClass('klyp-gftohs-status--checking klyp-gftohs-status--connected klyp-gftohs-status--error klyp-gftohs-status--unconfigured')
            .addClass('klyp-gftohs-status--' + state);
        $status.find('.klyp-gftohs-status__text').text(message);
        $status.find('.klyp-gftohs-status__retry').prop('hidden', state === 'checking');
    }

    function renderForms(forms) {
        if (!forms || !forms.length) {
            $list.html($('<p class="klyp-gftohs-muted"></p>').text(klypGfToHs.i18n.noForms));
            return;
        }

        var $ul = $('<ul></ul>');

        forms.forEach(function (form) {
            var $button = $('<button type="button" class="button button-small"></button>')
                .text(klypGfToHs.i18n.copy)
                .on('click', function () {
                    var button = this;
                    copy(form.id, function () {
                        $(button).text(klypGfToHs.i18n.copied);
                        setTimeout(function () { $(button).text(klypGfToHs.i18n.copy); }, 1600);
                    });
                });

            $ul.append(
                $('<li></li>').append(
                    $('<span></span>')
                        .append($('<span class="klyp-gftohs-form-list__name"></span>').text(form.name))
                        .append($('<span class="klyp-gftohs-form-list__id"></span>').text(form.id)),
                    $button
                )
            );
        });

        $list.empty().append($ul);
    }

    // Clipboard API needs a secure context; fall back to a temporary field.
    function copy(text, done) {
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done, function () { fallback(text, done); });
            return;
        }
        fallback(text, done);
    }

    function fallback(text, done) {
        var $tmp = $('<textarea readonly></textarea>')
            .css({ position: 'fixed', top: '-1000px', opacity: 0 })
            .val(text)
            .appendTo(document.body);
        $tmp[0].select();
        try { document.execCommand('copy'); done(); } catch (e) { /* nothing to do */ }
        $tmp.remove();
    }

    function test() {
        setStatus('checking', klypGfToHs.i18n.checking);

        $.post(klypGfToHs.ajaxUrl, {
            action: 'klyp_gftohs_test_connection',
            _ajax_nonce: klypGfToHs.nonce
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                setStatus('error', klypGfToHs.i18n.failed);
                return;
            }
            setStatus(response.data.state, response.data.message);
            if (response.data.state === 'connected') {
                renderForms(response.data.forms);
            } else {
                $list.html($('<p class="klyp-gftohs-muted"></p>').text(klypGfToHs.i18n.unavail));
            }
        }).fail(function () {
            setStatus('error', klypGfToHs.i18n.failed);
        });
    }

    $(function () {
        $status = $('#klyp-gftohs-status');
        $list = $('#klyp-gftohs-form-list');

        if (!$status.length) {
            return;
        }

        $status.find('.klyp-gftohs-status__retry').on('click', test);

        if (klypGfToHs.configured) {
            test();
        } else {
            setStatus('unconfigured', $status.find('.klyp-gftohs-status__text').text());
        }
    });
}(jQuery));
JS;
}

/**
 * Test the stored credentials against Hubspot
 *
 * Runs from the settings screen after the page has painted, so a slow or
 * unreachable Hubspot never delays the page itself.
 *
 * @return void
 */
function klypGFToHubspotTestConnection()
{
    if (! current_user_can('manage_options')) {
        wp_send_json_error(array('message' => __('You do not have permission to do this.', 'klyp-gf-to-hubspot')), 403);
    }

    check_ajax_referer('klyp_gftohs_test_connection');

    $hubspot = new klypHubspot();
    $missing = array();

    if ($hubspot->portalId === '') {
        $missing[] = __('Portal ID', 'klyp-gf-to-hubspot');
    }

    if (! $hubspot->hasCredentials()) {
        $missing[] = __('Private App access token', 'klyp-gf-to-hubspot');
    }

    if (! empty($missing)) {
        wp_send_json_success(
            array(
                'state'   => 'unconfigured',
                'message' => sprintf(
                    /* translators: %s: comma separated list of missing settings */
                    __('Not configured yet — still needed: %s.', 'klyp-gf-to-hubspot'),
                    implode(', ', $missing)
                ),
            )
        );
    }

    $forms = $hubspot->listForms();

    if ($hubspot->lastError !== '') {
        wp_send_json_success(
            array(
                'state'   => 'error',
                'message' => sprintf(
                    /* translators: %s: error message returned by Hubspot */
                    __('Hubspot rejected the request: %s', 'klyp-gf-to-hubspot'),
                    $hubspot->lastError
                ),
            )
        );
    }

    wp_send_json_success(
        array(
            'state'   => 'connected',
            'message' => sprintf(
                /* translators: 1: portal ID, 2: number of forms */
                _n(
                    'Connected to portal %1$s — %2$s form available.',
                    'Connected to portal %1$s — %2$s forms available.',
                    count($forms),
                    'klyp-gf-to-hubspot'
                ),
                $hubspot->portalId,
                number_format_i18n(count($forms))
            ),
            'forms'   => array_map(
                function ($form) {
                    return array('id' => $form->id, 'name' => $form->name);
                },
                $forms
            ),
        )
    );
}
add_action('wp_ajax_klyp_gftohs_test_connection', 'klypGFToHubspotTestConnection');

/**
 * Handle the "clear cached Hubspot fields" action
 *
 * @return void
 */
function klypGFToHubspotFlushCache()
{
    if (! current_user_can('manage_options')) {
        wp_die(esc_html__('You do not have permission to perform this action.', 'klyp-gf-to-hubspot'));
    }

    check_admin_referer('klyp_gftohs_flush_cache');

    klypHubspot::flushCache();

    wp_safe_redirect(
        add_query_arg(
            array(
                'page'    => 'klyp-gf-to-hubspot',
                'flushed' => '1',
            ),
            admin_url('options-general.php')
        )
    );
    exit;
}
add_action('admin_post_klyp_gftohs_flush_cache', 'klypGFToHubspotFlushCache');
