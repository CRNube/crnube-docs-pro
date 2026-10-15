<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Admin\LibraryPage;
use CRNube\Docs\Admin\SettingsPage;
use CRNube\Docs\Capabilities;

/**
 * Documents > Access: access groups, their members and expiry dates. A React app built
 * from assets/src/access.
 */
final class AccessPage
{
    public const SLUG = 'crnd-access';

    private string $hook = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function menu(): void
    {
        $this->hook = (string) add_submenu_page(
            LibraryPage::SLUG,
            __('Protected area', 'crnube-docs-pro'),
            __('Access', 'crnube-docs-pro'),
            Capabilities::MANAGE_ACCESS,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }
        $asset = CRND_PRO_DIR . 'build/access.asset.php';
        if (!is_readable($asset)) {
            return;
        }
        /** @var array{dependencies: list<string>, version: string} $meta */
        $meta = require $asset;

        wp_enqueue_script('crnd-access', CRND_PRO_URL . 'build/access.js', $meta['dependencies'], $meta['version'], true);
        wp_enqueue_style('crnd-access', CRND_PRO_URL . 'build/access.css', ['wp-components'], $meta['version']);
        wp_style_add_data('crnd-access', 'rtl', 'replace');
        wp_set_script_translations('crnd-access', 'crnube-docs-pro', CRND_PRO_DIR . 'languages');

        wp_add_inline_script('crnd-access', 'window.crndAccess = ' . wp_json_encode([
            'settingsUrl' => current_user_can(Capabilities::MANAGE_SETTINGS) ? admin_url('admin.php?page=' . SettingsPage::SLUG) : '',
            'libraryUrl'  => LibraryPage::url(),
            'pagesUrl'    => admin_url('edit.php?post_type=page'),
            'importMax'   => 500,
            'today'       => wp_date('Y-m-d'),
        ]) . ';', 'before');
    }

    public function render(): void
    {
        if (!is_readable(CRND_PRO_DIR . 'build/access.js')) {
            echo '<div class="wrap"><div class="notice notice-error"><p>'
                . esc_html__('The library app is not built. Run "npm install && npm run build" in the plugin folder.', 'crnube-docs-pro')
                . '</p></div></div>';
            return;
        }
        echo '<div class="wrap crnd-access-wrap"><div id="crnd-access-root"></div></div>';
    }
}
