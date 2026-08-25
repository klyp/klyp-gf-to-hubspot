<?php

// See if wordpress is properly installed
defined('ABSPATH') || die('Wordpress is not installed properly.');

$klypGfToHsPortalId = (string) get_option('klyp_gftohs_portal_id', '');
$klypGfToHsToken    = (string) get_option('klyp_gftohs_access_token', '');
$klypGfToHsReady    = $klypGfToHsPortalId !== '' && $klypGfToHsToken !== '';

// Gravity Forms already wired up to a Hubspot form.
$klypGfToHsForms = array();

if (class_exists('GFAPI')) {
    foreach ((array) GFAPI::get_forms() as $klypGfForm) {
        $klypGfHsFormId = trim((string) rgar($klypGfForm, 'klyp-gf-to-hubspot-form-id'));

        if ($klypGfHsFormId === '') {
            continue;
        }

        $klypGfMapped = 0;

        foreach ((array) rgar($klypGfForm, 'fields') as $klypGfField) {
            if (trim((string) rgobj($klypGfField, 'field_gf_to_hs_map')) !== '') {
                $klypGfMapped++;
            }
        }

        $klypGfToHsForms[] = array(
            'id'       => (int) rgar($klypGfForm, 'id'),
            'title'    => (string) rgar($klypGfForm, 'title'),
            'hsFormId' => $klypGfHsFormId,
            'mapped'   => $klypGfMapped,
        );
    }
}

?>

<div class="wrap klyp-gftohs">

    <h1><?php esc_html_e('Klyp Gravity Form to Hubspot', 'klyp-gf-to-hubspot'); ?></h1>
    <p class="klyp-gftohs__lede"><?php esc_html_e('Send Gravity Forms submissions to a Hubspot form. Connect your account here, then map fields on each individual form.', 'klyp-gf-to-hubspot'); ?></p>

    <?php if (! empty($_GET['flushed'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Cached Hubspot fields cleared.', 'klyp-gf-to-hubspot'); ?></p></div>
    <?php endif; ?>

    <?php if (! empty($_GET['settings-updated'])) : ?>
        <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Settings saved.', 'klyp-gf-to-hubspot'); ?></p></div>
    <?php endif; ?>

    <?php // Connection status. Filled in after load so Hubspot never delays the page. ?>
    <div id="klyp-gftohs-status" class="klyp-gftohs-status klyp-gftohs-status--checking" aria-live="polite">
        <span class="klyp-gftohs-status__dot" aria-hidden="true"></span>
        <span class="klyp-gftohs-status__text">
            <?php
            echo $klypGfToHsReady
                ? esc_html__('Checking connection to Hubspot…', 'klyp-gf-to-hubspot')
                : esc_html__('Not connected yet. Add your portal ID and access token below.', 'klyp-gf-to-hubspot');
            ?>
        </span>
        <button type="button" class="button button-small klyp-gftohs-status__retry" hidden><?php esc_html_e('Test again', 'klyp-gf-to-hubspot'); ?></button>
    </div>

    <div class="klyp-gftohs__columns">

        <div class="klyp-gftohs__main">

            <div class="card klyp-gftohs-card">
                <h2 class="title"><?php esc_html_e('1. Connect your Hubspot account', 'klyp-gf-to-hubspot'); ?></h2>

                <form method="post" action="<?php echo esc_url(admin_url('options.php')); ?>">
                    <?php settings_fields('KlypGFToHubspot'); ?>

                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row">
                                <label for="klyp_gftohs_portal_id"><?php esc_html_e('Portal ID', 'klyp-gf-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <input type="text" id="klyp_gftohs_portal_id" name="klyp_gftohs_portal_id" class="regular-text code" value="<?php echo esc_attr($klypGfToHsPortalId); ?>" inputmode="numeric" pattern="[0-9]*" placeholder="12345678" aria-describedby="klyp_gftohs_portal_id_help">
                                <p class="description" id="klyp_gftohs_portal_id_help">
                                    <?php esc_html_e('The numeric hub ID of your Hubspot account.', 'klyp-gf-to-hubspot'); ?>
                                    <a href="https://knowledge.hubspot.com/account-management/manage-multiple-hubspot-accounts" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Where do I find it?', 'klyp-gf-to-hubspot'); ?></a>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="klyp_gftohs_access_token"><?php esc_html_e('Private App token', 'klyp-gf-to-hubspot'); ?></label>
                            </th>
                            <td>
                                <?php // The stored token is deliberately never rendered back into the page. ?>
                                <input type="password" id="klyp_gftohs_access_token" name="klyp_gftohs_access_token" class="regular-text code" value="" autocomplete="new-password" spellcheck="false" aria-describedby="klyp_gftohs_token_help" placeholder="<?php echo $klypGfToHsToken !== '' ? esc_attr__('Leave blank to keep the stored token', 'klyp-gf-to-hubspot') : 'pat-xxx-••••'; ?>">

                                <p class="description" id="klyp_gftohs_token_help">
                                    <?php esc_html_e('Create a Private App with the "forms" scope, then paste its access token.', 'klyp-gf-to-hubspot'); ?>
                                    <a href="https://developers.hubspot.com/docs/guides/apps/private-apps/overview" target="_blank" rel="noopener noreferrer"><?php esc_html_e('How do I create one?', 'klyp-gf-to-hubspot'); ?></a>
                                </p>

                                <?php if ($klypGfToHsToken !== '') : ?>
                                    <p class="description klyp-gftohs-token-stored">
                                        <span class="dashicons dashicons-lock" aria-hidden="true"></span>
                                        <?php
                                            printf(
                                                /* translators: %s: masked token, e.g. "pat-••••4f2a" */
                                                esc_html__('Stored: %s', 'klyp-gf-to-hubspot'),
                                                '<code>' . esc_html(klypGFToHubspotMaskToken($klypGfToHsToken)) . '</code>'
                                            );
                                        ?>
                                    </p>
                                    <p>
                                        <label for="klyp_gftohs_clear_token">
                                            <input type="checkbox" id="klyp_gftohs_clear_token" name="klyp_gftohs_clear_token" value="1">
                                            <?php esc_html_e('Remove the stored token', 'klyp-gf-to-hubspot'); ?>
                                        </label>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>

                    <?php submit_button(__('Save settings', 'klyp-gf-to-hubspot')); ?>
                </form>
            </div>

            <div class="card klyp-gftohs-card">
                <h2 class="title"><?php esc_html_e('2. Connect a form', 'klyp-gf-to-hubspot'); ?></h2>

                <?php if (empty($klypGfToHsForms)) : ?>
                    <p><?php esc_html_e('No Gravity Form is sending to Hubspot yet.', 'klyp-gf-to-hubspot'); ?></p>
                    <p class="description">
                        <?php esc_html_e('Open a form, go to Form Settings → Hubspot Settings, paste a Hubspot form ID and save. Each field then gets a "Hubspot field to map" setting under its Advanced tab.', 'klyp-gf-to-hubspot'); ?>
                    </p>
                    <?php if (class_exists('GFAPI')) : ?>
                        <p>
                            <a class="button" href="<?php echo esc_url(admin_url('admin.php?page=gf_edit_forms')); ?>"><?php esc_html_e('Go to Gravity Forms', 'klyp-gf-to-hubspot'); ?></a>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <table class="wp-list-table widefat striped klyp-gftohs-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e('Gravity Form', 'klyp-gf-to-hubspot'); ?></th>
                                <th scope="col"><?php esc_html_e('Hubspot form ID', 'klyp-gf-to-hubspot'); ?></th>
                                <th scope="col" class="klyp-gftohs-table__num"><?php esc_html_e('Mapped fields', 'klyp-gf-to-hubspot'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($klypGfToHsForms as $klypGfToHsRow) : ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url(admin_url('admin.php?page=gf_edit_forms&id=' . $klypGfToHsRow['id'])); ?>"><strong><?php echo esc_html($klypGfToHsRow['title']); ?></strong></a>
                                    </td>
                                    <td><code class="klyp-gftohs-guid"><?php echo esc_html($klypGfToHsRow['hsFormId']); ?></code></td>
                                    <td class="klyp-gftohs-table__num">
                                        <?php if ($klypGfToHsRow['mapped'] === 0) : ?>
                                            <span class="klyp-gftohs-warn" title="<?php esc_attr_e('No fields are mapped, so nothing will be sent.', 'klyp-gf-to-hubspot'); ?>">
                                                <span class="dashicons dashicons-warning" aria-hidden="true"></span> <?php esc_html_e('none', 'klyp-gf-to-hubspot'); ?>
                                            </span>
                                        <?php else : ?>
                                            <?php echo esc_html(number_format_i18n($klypGfToHsRow['mapped'])); ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="klyp-gftohs__side">

            <div class="card klyp-gftohs-card">
                <h2 class="title"><?php esc_html_e('Your Hubspot forms', 'klyp-gf-to-hubspot'); ?></h2>
                <p class="description"><?php esc_html_e('Copy an ID here and paste it into a Gravity Form’s Hubspot Settings.', 'klyp-gf-to-hubspot'); ?></p>
                <div id="klyp-gftohs-form-list" class="klyp-gftohs-form-list">
                    <p class="klyp-gftohs-muted">
                        <?php
                        echo $klypGfToHsReady
                            ? esc_html__('Loading…', 'klyp-gf-to-hubspot')
                            : esc_html__('Available once your account is connected.', 'klyp-gf-to-hubspot');
                        ?>
                    </p>
                </div>
            </div>

            <div class="card klyp-gftohs-card">
                <h2 class="title"><?php esc_html_e('Cached field lists', 'klyp-gf-to-hubspot'); ?></h2>
                <p class="description"><?php esc_html_e('Hubspot form fields are cached for 15 minutes. Clear the cache after changing a form in Hubspot.', 'klyp-gf-to-hubspot'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="klyp_gftohs_flush_cache">
                    <?php wp_nonce_field('klyp_gftohs_flush_cache'); ?>
                    <?php submit_button(__('Clear cache', 'klyp-gf-to-hubspot'), 'secondary', 'submit', false); ?>
                </form>
            </div>
        </div>
    </div>
</div>
