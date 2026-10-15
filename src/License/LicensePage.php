<?php
declare(strict_types=1);

namespace CRNube\DocsPro\License;

use CRNube\Docs\Admin\LibraryPage;
use CRNube\Docs\Capabilities;

/**
 * Documents › License: activate the key, see its status, free this site.
 */
final class LicensePage
{
    public const SLUG = 'crnd-license';

    private const ACTION = 'crnd_pro_license';

    public function __construct(private readonly License $license)
    {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu'], 12);
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
        add_action('admin_notices', [$this, 'notice']);
        add_filter('plugin_action_links_' . plugin_basename(CRND_PRO_FILE), [$this, 'actionLinks']);
    }

    public function menu(): void
    {
        add_submenu_page(
            LibraryPage::SLUG,
            __('CRNube Docs Pro license', 'crnube-docs-pro'),
            __('License', 'crnube-docs-pro'),
            Capabilities::MANAGE_SETTINGS,
            self::SLUG,
            [$this, 'render']
        );
    }

    public static function url(): string
    {
        return admin_url('admin.php?page=' . self::SLUG);
    }

    public function enqueue(string $hook): void
    {
        if (str_ends_with($hook, '_page_' . self::SLUG)) {
            wp_enqueue_style('crnd-pro-admin', CRND_PRO_URL . 'assets/css/admin.css', [], \CRNube\DocsPro\Pro::assetVersion('assets/css/admin.css'));
        }
    }

    /**
     * @param array<string, string> $links
     * @return array<string, string>
     */
    public function actionLinks(array $links): array
    {
        if (current_user_can(Capabilities::MANAGE_SETTINGS)) {
            array_unshift($links, sprintf('<a href="%s">%s</a>', esc_url(self::url()), esc_html__('License', 'crnube-docs-pro')));
        }
        return $links;
    }

    public function handle(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS)) {
            wp_die(esc_html__('You cannot manage the license.', 'crnube-docs-pro'), '', ['response' => 403]);
        }
        check_admin_referer(self::ACTION);

        $do      = sanitize_key(wp_unslash($_POST['do'] ?? ''));
        $message = '';
        if ($do === 'activate') {
            $result  = $this->license->activate(sanitize_text_field(wp_unslash($_POST['license_key'] ?? '')));
            $message = is_wp_error($result) ? $result->get_error_code() : $result;
        } elseif ($do === 'deactivate') {
            $this->license->deactivate();
            $message = 'deactivated';
        } elseif ($do === 'check') {
            $this->license->check();
            $message = 'checked';
        }
        wp_safe_redirect(add_query_arg('crnd_msg', $message, self::url()));
        exit;
    }

    /** A reminder on the plugin's own screens only, while there is no valid license. */
    public function notice(): void
    {
        $screen = get_current_screen();
        if ($screen === null || !current_user_can(Capabilities::MANAGE_SETTINGS) || $this->license->isValid()) {
            return;
        }
        $ours = $screen->id === 'plugins' || str_contains($screen->id, LibraryPage::SLUG) || str_contains($screen->id, 'crnd-');
        if (!$ours || str_ends_with($screen->id, self::SLUG)) {
            return;
        }
        $data = $this->license->data();
        $text = $data['key'] === ''
            ? __('Activate your CRNube Docs Pro license to receive updates and support.', 'crnube-docs-pro')
            : License::message($data['status']);
        printf(
            '<div class="notice notice-warning"><p>%s <a href="%s">%s</a></p></div>',
            esc_html($text),
            esc_url(self::url()),
            esc_html__('Go to the license', 'crnube-docs-pro')
        );
    }

    public function render(): void
    {
        if (!current_user_can(Capabilities::MANAGE_SETTINGS)) {
            return;
        }
        $data  = $this->license->data();
        $valid = $this->license->isValid();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
        $msg = sanitize_key(wp_unslash($_GET['crnd_msg'] ?? ''));
        ?>
        <div class="wrap crnd-license">
            <h1><?php esc_html_e('CRNube Docs Pro license', 'crnube-docs-pro'); ?></h1>

            <?php if ($msg !== '') : ?>
                <?php
                $ok   = in_array($msg, ['valid', 'deactivated', 'checked'], true);
                $text = match ($msg) {
                    'deactivated' => __('The license was removed from this site.', 'crnube-docs-pro'),
                    'checked'     => __('License checked.', 'crnube-docs-pro'),

                    default       => License::message($msg),
                };
                ?>
                <div class="notice <?php echo $ok ? 'notice-success' : 'notice-error'; ?> is-dismissible"><p><?php echo esc_html($text); ?></p></div>
            <?php endif; ?>

            <div class="crnd-license__card">
                <?php if ($data['key'] === '') : ?>
                    <p><?php esc_html_e('Type the license key you received by email. It is checked against crnube.com and registers this site.', 'crnube-docs-pro'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="crnd-license__form">
                        <?php wp_nonce_field(self::ACTION); ?>
                        <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
                        <input type="hidden" name="do" value="activate">
                        <label for="crnd-license-key" class="screen-reader-text"><?php esc_html_e('License key', 'crnube-docs-pro'); ?></label>
                        <input type="text" id="crnd-license-key" name="license_key" class="regular-text code" required
                            autocomplete="off" spellcheck="false" placeholder="CRND-XXXXX-XXXXX-XXXXX-XXXXX">
                        <?php submit_button(__('Activate', 'crnube-docs-pro'), 'primary', 'submit', false); ?>
                    </form>
                <?php else : ?>
                    <table class="crnd-license__facts">
                        <tr>
                            <th scope="row"><?php esc_html_e('Status', 'crnube-docs-pro'); ?></th>
                            <td>
                                <span class="crnd-license__status <?php echo $valid ? 'is-valid' : 'is-invalid'; ?>">
                                    <span class="dashicons <?php echo $valid ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
                                    <?php echo esc_html($valid ? __('Active', 'crnube-docs-pro') : __('Not active', 'crnube-docs-pro')); ?>
                                </span>
                                <p class="description"><?php echo esc_html(License::message($data['status'])); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Key', 'crnube-docs-pro'); ?></th>
                            <td><code><?php echo esc_html($this->license->maskedKey()); ?></code></td>
                        </tr>
                        <?php if ($data['customer'] !== '') : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Licensed to', 'crnube-docs-pro'); ?></th>
                                <td><?php echo esc_html($data['customer']); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Valid until', 'crnube-docs-pro'); ?></th>
                            <td><?php echo esc_html($data['expires'] !== '' ? (string) wp_date((string) get_option('date_format'), (int) strtotime($data['expires'] . ' 12:00:00')) : __('No expiry', 'crnube-docs-pro')); ?></td>
                        </tr>
                        <?php if ($data['sites_max'] > 0) : ?>
                            <tr>
                                <th scope="row"><?php esc_html_e('Sites', 'crnube-docs-pro'); ?></th>
                                <td>
                                    <?php
                                    /* translators: 1: sites in use, 2: sites allowed */
                                    echo esc_html(sprintf(__('%1$d of %2$d in use', 'crnube-docs-pro'), $data['sites_used'], $data['sites_max']));
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row"><?php esc_html_e('Last check', 'crnube-docs-pro'); ?></th>
                            <td>
                                <?php echo esc_html($data['checked'] > 0 ? (string) wp_date((string) get_option('date_format') . ' ' . (string) get_option('time_format'), $data['checked']) : '—'); ?>
                                <?php if ($data['error'] !== '') : ?>
                                    <p class="description"><?php echo esc_html($data['error']); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <div class="crnd-license__actions">
                        <?php $this->button('check', __('Check now', 'crnube-docs-pro'), 'secondary'); ?>
                        <?php $this->button('deactivate', __('Remove from this site', 'crnube-docs-pro'), 'link-delete'); ?>
                    </div>
                <?php endif; ?>
                <p class="crnd-license__note">
                    <?php esc_html_e('The license gives updates and support. If it expires, the protected area and the reports keep working exactly the same.', 'crnube-docs-pro'); ?>
                </p>
            </div>
        </div>
        <?php
    }

    private function button(string $do, string $label, string $class): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field(self::ACTION); ?>
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION); ?>">
            <input type="hidden" name="do" value="<?php echo esc_attr($do); ?>">
            <button type="submit" class="button <?php echo esc_attr($class === 'link-delete' ? 'button-link button-link-delete' : 'button-' . $class); ?>"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }
}
