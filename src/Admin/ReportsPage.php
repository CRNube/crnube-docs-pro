<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Admin;

use CRNube\Docs\Admin\LibraryPage;
use CRNube\Docs\Admin\SettingsPage;
use CRNube\Docs\Capabilities;

/**
 * Documents > Reports: views and downloads over time, top documents, devices, browsers
 * and countries, with CSV export. A React app built from assets/src/reports.
 */
final class ReportsPage
{
    public const SLUG = 'crnd-reports';

    private string $hook = '';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 11);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public static function url(int $documentId = 0): string
    {
        $args = ['page' => self::SLUG];
        if ($documentId > 0) {
            $args['document'] = $documentId;
        }
        return add_query_arg($args, admin_url('admin.php'));
    }

    public function menu(): void
    {
        $this->hook = (string) add_submenu_page(
            LibraryPage::SLUG,
            __('Document reports', 'crnube-docs-pro'),
            __('Reports', 'crnube-docs-pro'),
            Capabilities::VIEW_REPORTS,
            self::SLUG,
            [$this, 'render']
        );
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== $this->hook) {
            return;
        }
        $asset = CRND_PRO_DIR . 'build/reports.asset.php';
        if (!is_readable($asset)) {
            return;
        }
        /** @var array{dependencies: list<string>, version: string} $meta */
        $meta = require $asset;

        wp_enqueue_script('crnd-reports', CRND_PRO_URL . 'build/reports.js', $meta['dependencies'], $meta['version'], true);
        wp_enqueue_style('crnd-reports', CRND_PRO_URL . 'build/reports.css', ['wp-components'], $meta['version']);
        wp_style_add_data('crnd-reports', 'rtl', 'replace');
        wp_set_script_translations('crnd-reports', 'crnube-docs-pro', CRND_PRO_DIR . 'languages');

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: preselects a document
        $document = absint($_GET['document'] ?? 0);

        wp_add_inline_script('crnd-reports', 'window.crndReports = ' . wp_json_encode([
            'exportUrl'  => add_query_arg([
                'action'   => ReportsExport::ACTION,
                '_wpnonce' => wp_create_nonce(ReportsExport::ACTION),
            ], admin_url('admin-post.php')),
            'libraryUrl' => LibraryPage::url(),
            'document'   => $document,
            'documentTitle' => $document > 0 ? get_the_title($document) : '',
            'today'      => wp_date('Y-m-d'),
            'locale'     => str_replace('_', '-', get_user_locale()),
            'tracking'   => (bool) \CRNube\Docs\Plugin::instance()->settings->get('track_enabled'),
            'settingsUrl' => current_user_can(Capabilities::MANAGE_SETTINGS) ? admin_url('admin.php?page=' . SettingsPage::SLUG) : '',
        ]) . ';', 'before');
    }

    public function render(): void
    {
        if (!is_readable(CRND_PRO_DIR . 'build/reports.js')) {
            echo '<div class="wrap"><div class="notice notice-error"><p>'
                . esc_html__('The library app is not built. Run "npm install && npm run build" in the plugin folder.', 'crnube-docs-pro')
                . '</p></div></div>';
            return;
        }
        echo '<div class="wrap crnd-reports-wrap"><div id="crnd-reports-root"></div></div>';
    }
}
