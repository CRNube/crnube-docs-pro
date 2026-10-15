<?php
/**
 * Plugin Name:       CRNube Docs Pro
 * Plugin URI:        https://crnube.com
 * Description:       Protected area for CRNube Docs (access groups with expiry, members' portal with emailed codes, private files) and reports of who viewed and downloaded what.
 * Version:           0.7.0
 * Author:            CRNube
 * Author URI:        https://crnube.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  crnube-docs
 * Text Domain:       crnube-docs-pro
 * Domain Path:       /languages
 * Update URI:        https://crnube.com/crnube-docs-pro
 *
 * This file must stay parseable by old PHP versions: the namespaced classes in src/
 * use PHP 8.1 syntax and are only loaded after the requirements check passes.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('CRND_PRO_VERSION', '0.7.0');
define('CRND_PRO_FILE', __FILE__);
define('CRND_PRO_DIR', plugin_dir_path(__FILE__));
define('CRND_PRO_URL', plugin_dir_url(__FILE__));
/** Oldest CRNube Docs this version works with (the one with the extension points). */
define('CRND_PRO_MIN_CORE', '0.6.0');
/**
 * Public key (Ed25519, base64) that checks the signature of every update before it is
 * installed. Releases are signed offline with the matching secret key, so not even a
 * compromised license server can push a modified plugin.
 */
define('CRND_PRO_RELEASE_KEY', 'lSB4/5y7quX5u5fPYdrJTib8/e+Au+fk20DjZi+7YE4=');

if (version_compare(PHP_VERSION, '8.1', '<')) {
    return;
}

spl_autoload_register(function ($class) {
    $prefix = 'CRNube\\DocsPro\\';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $file = CRND_PRO_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_readable($file)) {
        require $file;
    }
});

register_activation_hook(__FILE__, [\CRNube\DocsPro\Installer::class, 'activate']);
register_deactivation_hook(__FILE__, [\CRNube\DocsPro\Installer::class, 'deactivate']);

// Before CRNube Docs boots (plugins_loaded, priority 10): it applies Pro's filters then.
add_action('plugins_loaded', function () {
    if (!defined('CRND_VERSION') || version_compare(CRND_VERSION, CRND_PRO_MIN_CORE, '<')) {
        add_action('admin_notices', function () {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            echo '<div class="notice notice-error"><p>';
            echo esc_html(sprintf(
                /* translators: %s: version number */
                __('CRNube Docs Pro needs CRNube Docs %s or newer, active. Until then the protected area and the reports are off; nothing was deleted.', 'crnube-docs-pro'),
                CRND_PRO_MIN_CORE
            ));
            echo '</p></div>';
        });
        return;
    }
    \CRNube\DocsPro\Pro::preload();
}, 5);
