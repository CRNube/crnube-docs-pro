<?php
declare(strict_types=1);

namespace CRNube\DocsPro;

use CRNube\Docs\Plugin;
use CRNube\DocsPro\Access\GroupAccessPolicy;
use CRNube\DocsPro\Access\Otp;
use CRNube\DocsPro\Access\Portal;
use CRNube\DocsPro\Access\PrivateStorage;
use CRNube\DocsPro\Admin\AccessMetaBox;
use CRNube\DocsPro\Admin\AccessPage;
use CRNube\DocsPro\Admin\AssistantMetaBox;
use CRNube\DocsPro\Admin\AssistantPage;
use CRNube\DocsPro\Assistant\Indexer;
use CRNube\DocsPro\Assistant\RestController as AssistantController;
use CRNube\DocsPro\Assistant\Usage as AssistantUsage;
use CRNube\DocsPro\Assistant\Widget as AssistantWidgetShortcode;
use CRNube\DocsPro\Integrations\AssistantWidget;
use CRNube\DocsPro\Admin\LibraryExtension;
use CRNube\DocsPro\Admin\ReportsExport;
use CRNube\DocsPro\Admin\ReportsPage;
use CRNube\DocsPro\Admin\SettingsCards;
use CRNube\DocsPro\Analytics\Recorder;
use CRNube\DocsPro\Analytics\Retention;
use CRNube\DocsPro\Integrations\PortalWidget;
use CRNube\DocsPro\License\License;
use CRNube\DocsPro\License\LicensePage;
use CRNube\DocsPro\License\Updater;
use CRNube\DocsPro\Rest\AccessController;
use CRNube\DocsPro\Rest\StatsController;

/**
 * CRNube Docs Pro: the protected area (groups, members' portal, private files), the
 * reports and the document assistant, plugged into CRNube Docs through its filters and
 * actions.
 *
 * The license only unlocks updates and support. Nothing stops working when it expires:
 * a cooperative's private documents must never become unreachable (or public) because
 * of a renewal.
 */
final class Pro
{
    private static ?self $instance = null;

    public readonly Portal $portal;
    public readonly License $license;

    private function __construct(public readonly Plugin $core)
    {
        $this->portal  = new Portal($core->settings, new Otp());
        $this->license = new License();
    }

    /** Before CRNube Docs builds its services: the filters it applies while doing so. */
    public static function preload(): void
    {
        add_filter('crnd_access_policy', static fn (): GroupAccessPolicy => new GroupAccessPolicy());
        add_filter('crnd_settings_defaults', [SettingsCards::class, 'defaults']);
        add_filter('crnd_settings_sanitize', [SettingsCards::class, 'sanitize'], 10, 2);
        add_action('crnd_loaded', [self::class, 'boot']);
    }

    public static function boot(Plugin $core): void
    {
        if (self::$instance !== null) {
            return;
        }
        self::$instance = new self($core);
        self::$instance->register();
    }

    /**
     * Version of a CSS or JS file for the browser cache: the plugin version plus the
     * file's modification time, so a changed file is never served stale.
     */
    public static function assetVersion(string $relativePath): string
    {
        $mtime = @filemtime(CRND_PRO_DIR . $relativePath); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file just keeps the plugin version
        return $mtime !== false ? CRND_PRO_VERSION . '.' . $mtime : CRND_PRO_VERSION;
    }

    public static function instance(): self
    {
        if (self::$instance === null) {
            throw new \LogicException('CRNube Docs Pro has not booted: CRNube Docs is not active.');
        }
        return self::$instance;
    }

    private function register(): void
    {
        load_plugin_textdomain('crnube-docs-pro', false, dirname(plugin_basename(CRND_PRO_FILE)) . '/languages');
        add_action('init', [Installer::class, 'maybeUpgrade'], 1);

        (new PrivateStorage())->register();
        (new Recorder($this->core->settings))->register();
        (new Retention($this->core->settings))->register();
        (new AccessController())->register();
        (new StatsController())->register();
        $this->portal->register();
        $this->license->register();
        (new Updater($this->license))->register();
        (new AssistantController())->register();
        (new AssistantWidgetShortcode())->register();
        (new Indexer())->register();
        AssistantUsage::register();

        // The widget goes in the "CRNube Docs" category that CRNube Docs registers.
        add_action('elementor/widgets/register', static function ($widgets): void {
            /** @var \Elementor\Widgets_Manager $widgets */
            $widgets->register(new PortalWidget());
            $widgets->register(new AssistantWidget());
        });

        if (is_admin()) {
            (new AccessPage())->register();
            (new ReportsPage())->register();
            (new ReportsExport())->register();
            (new AccessMetaBox())->register();
            (new SettingsCards())->register();
            (new LibraryExtension())->register();
            (new LicensePage($this->license))->register();
            (new AssistantPage())->register();
            (new AssistantMetaBox())->register();
        }
    }
}
