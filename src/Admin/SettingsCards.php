<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Admin\SettingsPage;
use CRNube\Docs\Capabilities;
use CRNube\Docs\Plugin;
use CRNube\Docs\Settings\Settings;
use CRNube\DocsPro\Access\Portal;

/**
 * The protected area and reports settings: their defaults and validation (stored in the
 * CRNube Docs option), and their cards in Documents › Settings.
 */
final class SettingsCards
{
    public function register(): void
    {
        add_action('crnd_settings_cards', [$this, 'protectedCard'], 10);
        add_action('crnd_settings_cards', [$this, 'statsCard'], 20);
        add_action('admin_post_crnd_create_portal', [$this, 'createPortalPage']);
    }

    /**
     * Added before CRNube Docs builds its settings, so it runs at load time.
     *
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    public static function defaults(array $defaults): array
    {
        return $defaults + [
            'portal_page'      => 0,
            'session_hours'    => 8,
            'respect_gpc'      => true,
            'retention_months' => 24,
        ];
    }

    /**
     * @param array<string, mixed> $clean
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public static function sanitize(array $clean, array $input): array
    {
        $page                      = absint($input['portal_page'] ?? 0);
        $clean['portal_page']      = $page > 0 && get_post_type($page) === 'page' ? $page : 0;
        $clean['session_hours']    = max(1, min(720, (int) ($input['session_hours'] ?? 8)));
        $clean['retention_months'] = max(0, min(120, (int) ($input['retention_months'] ?? 24)));
        $clean['respect_gpc']      = !empty($input['respect_gpc']);
        return $clean;
    }

    /** @param array<string, mixed> $v */
    public function protectedCard(array $v): void
    {
        $portal = (int) $v['portal_page'];
        ?>
        <section class="crnd-card" id="crnd-protected">
            <header class="crnd-card__header">
                <h2><?php esc_html_e('Protected area', 'crnube-docs-pro'); ?></h2>
                <span class="crnd-card__hint"><?php esc_html_e('Members sign in on the portal page with a code sent to their email. Groups and members are managed in Documents › Access.', 'crnube-docs-pro'); ?></span>
            </header>
            <div class="crnd-field-row">
                <label class="crnd-field">
                    <span class="crnd-field__label"><?php esc_html_e('Portal page', 'crnube-docs-pro'); ?></span>
                    <?php
                    wp_dropdown_pages([
                        'name'              => esc_attr(SettingsPage::fieldName('portal_page')),
                        'selected'          => $portal, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- int
                        'show_option_none'  => esc_html__('— Use the WordPress login —', 'crnube-docs-pro'),
                        'option_none_value' => '0',
                    ]);
                    ?>
                </label>
                <label class="crnd-field">
                    <span class="crnd-field__label"><?php esc_html_e('Restricted documents in public libraries', 'crnube-docs-pro'); ?></span>
                    <select name="<?php echo esc_attr(SettingsPage::fieldName('private_display')); ?>">
                        <option value="lock" <?php selected($v['private_display'], 'lock'); ?>><?php esc_html_e('Show them with a lock and "Sign in"', 'crnube-docs-pro'); ?></option>
                        <option value="hide" <?php selected($v['private_display'], 'hide'); ?>><?php esc_html_e('Hide them from people without access', 'crnube-docs-pro'); ?></option>
                    </select>
                </label>
                <label class="crnd-field">
                    <span class="crnd-field__label"><?php esc_html_e('Members stay signed in for', 'crnube-docs-pro'); ?></span>
                    <select name="<?php echo esc_attr(SettingsPage::fieldName('session_hours')); ?>">
                        <?php foreach ([1, 2, 4, 8, 24, 72, 168] as $hours) : ?>
                            <option value="<?php echo (int) $hours; ?>" <?php selected((int) $v['session_hours'], $hours); ?>>
                                <?php
                                /* translators: %d: number of hours */
                                echo esc_html(sprintf(_n('%d hour', '%d hours', $hours, 'crnube-docs-pro'), $hours));
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
            </div>
            <?php if ($portal === 0 || get_post_status($portal) !== 'publish') : ?>
                <p>
                    <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=crnd_create_portal'), 'crnd_create_portal')); ?>">
                        <?php esc_html_e('Create the portal page', 'crnube-docs-pro'); ?>
                    </a>
                    <span class="description"><?php esc_html_e('A page with [crnube_docs_portal], ready to link from the menu.', 'crnube-docs-pro'); ?></span>
                </p>
            <?php else : ?>
                <p class="description">
                    <?php esc_html_e('Portal:', 'crnube-docs-pro'); ?>
                    <a href="<?php echo esc_url((string) get_permalink($portal)); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) get_permalink($portal)); ?></a>
                </p>
            <?php endif; ?>
        </section>
        <?php
    }

    /** Creates a published "Private documents" page with the portal shortcode and selects it. */
    public function createPortalPage(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS) || !current_user_can('publish_pages')) {
            wp_die(esc_html__('You cannot create pages.', 'crnube-docs-pro'), '', ['response' => 403]);
        }
        check_admin_referer('crnd_create_portal');

        $page = wp_insert_post([
            'post_type'    => 'page',
            'post_status'  => 'publish',
            'post_title'   => __('Private documents', 'crnube-docs-pro'),
            'post_name'    => 'documentos-privados',
            'post_content' => '[' . Portal::SHORTCODE . ']',
        ], true);
        if (!is_wp_error($page)) {
            $values                = Plugin::instance()->settings->all();
            $values['portal_page'] = (int) $page;
            update_option(Settings::OPTION, Settings::sanitize($values));
        }
        wp_safe_redirect(admin_url('admin.php?page=' . SettingsPage::SLUG . '#crnd-protected'));
        exit;
    }

    /** @param array<string, mixed> $v */
    public function statsCard(array $v): void
    {
        ?>
        <section class="crnd-card">
            <header class="crnd-card__header">
                <h2><?php esc_html_e('Reports and privacy', 'crnube-docs-pro'); ?></h2>
                <span class="crnd-card__hint"><?php esc_html_e('IP addresses are never stored: each visitor gets an anonymous key that changes every day.', 'crnube-docs-pro'); ?></span>
            </header>
            <div class="crnd-toggles">
                <label class="crnd-toggle">
                    <input type="checkbox" name="<?php echo esc_attr(SettingsPage::fieldName('respect_gpc')); ?>" value="1" <?php checked((bool) $v['respect_gpc']); ?>>
                    <span class="crnd-toggle__switch" aria-hidden="true"></span>
                    <span>
                        <?php esc_html_e('Respect the browser privacy signal', 'crnube-docs-pro'); ?>
                        <small><?php esc_html_e('Visitors who send "Do Not Track" or "Global Privacy Control" are counted without device or country.', 'crnube-docs-pro'); ?></small>
                    </span>
                </label>
            </div>
            <label class="crnd-field">
                <span class="crnd-field__label"><?php esc_html_e('Keep the history for', 'crnube-docs-pro'); ?></span>
                <select name="<?php echo esc_attr(SettingsPage::fieldName('retention_months')); ?>">
                    <?php foreach ([6, 12, 24, 36, 60, 0] as $months) : ?>
                        <option value="<?php echo (int) $months; ?>" <?php selected((int) $v['retention_months'], $months); ?>>
                            <?php
                            echo esc_html($months === 0
                                ? __('Forever', 'crnube-docs-pro')
                                /* translators: %d: number of months */
                                : sprintf(_n('%d month', '%d months', $months, 'crnube-docs-pro'), $months));
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
        </section>
        <?php
    }
}
