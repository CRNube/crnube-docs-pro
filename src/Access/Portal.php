<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

use CRNube\Docs\Plugin;
use CRNube\Docs\Frontend\TemplateLoader;
use CRNube\Docs\Settings\Settings;

/**
 * The members' entrance: [crnube_docs_portal] shows the sign-in with an emailed code,
 * and once signed in, the documents the member may open.
 *
 * The email typed in step one is never put in a URL: it is kept server-side, keyed by a
 * random token in a short-lived HttpOnly cookie.
 */
final class Portal
{
    public const SHORTCODE = 'crnube_docs_portal';

    private const COOKIE      = 'crnd_otp';
    private const FLOW_TTL    = 15 * MINUTE_IN_SECONDS;
    private const NONCE       = 'crnd_portal';
    private const MESSAGES    = ['sent', 'invalid', 'limit', 'error', 'logged_out', 'expired'];

    public function __construct(
        private readonly Settings $settings,
        private readonly Otp $otp,
    ) {
    }

    public function register(): void
    {
        add_shortcode(self::SHORTCODE, [$this, 'render']);
        foreach (['crnd_otp_request' => 'handleRequest', 'crnd_otp_verify' => 'handleVerify', 'crnd_otp_restart' => 'handleRestart'] as $action => $method) {
            add_action('admin_post_nopriv_' . $action, [$this, $method]);
            add_action('admin_post_' . $action, [$this, $method]);
        }
        add_action('admin_post_crnd_logout', [$this, 'handleLogout']);

        // Members are not staff: no admin screens, no toolbar, shorter sessions.
        add_action('admin_init', [$this, 'keepMembersOutOfAdmin']);
        add_filter('show_admin_bar', [$this, 'hideAdminBar']);
        add_filter('auth_cookie_expiration', [$this, 'sessionLength'], 10, 2);
        add_filter('login_redirect', [$this, 'loginRedirect'], 10, 3);

        // Locked documents in public libraries send visitors here instead of wp-login.php.
        add_filter('crnd_login_url', fn (string $url, string $target): string => $this->loginUrl($target), 10, 2);
    }

    public function url(): string
    {
        $page = (int) $this->settings->get('portal_page');
        $url  = $page > 0 && get_post_status($page) === 'publish' ? get_permalink($page) : '';
        return is_string($url) ? $url : '';
    }

    /** Where to sign in to reach $redirect (the portal if there is one). */
    public function loginUrl(string $redirect = ''): string
    {
        $portal = $this->url();
        if ($portal === '') {
            return wp_login_url($redirect);
        }
        return $redirect !== '' ? add_query_arg('redirect_to', rawurlencode($redirect), $portal) : $portal;
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function render(array|string $atts = []): string
    {
        $atts = shortcode_atts([
            'category' => '',
            'layout'   => '',
            'title'    => '',
        ], is_array($atts) ? $atts : [], self::SHORTCODE);

        wp_enqueue_style('crnd-frontend');
        wp_enqueue_style('crnd-pro-portal', CRND_PRO_URL . 'assets/css/portal.css', ['crnd-frontend'], \CRNube\DocsPro\Pro::assetVersion('assets/css/portal.css'));

        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only
        $message  = sanitize_key(wp_unslash($_GET['crnd_msg'] ?? ''));
        $redirect = isset($_GET['redirect_to']) ? wp_validate_redirect(esc_url_raw(wp_unslash($_GET['redirect_to'])), '') : '';
        // phpcs:enable

        $user = wp_get_current_user();
        $vars = [
            'message'  => in_array($message, self::MESSAGES, true) ? $message : '',
            'redirect' => $redirect,
            'nonce'    => self::NONCE,
            'action'   => admin_url('admin-post.php'),
            'step'     => $this->flowEmail() !== '' ? 'code' : 'email',
            'user'     => $user->ID > 0 ? $user : null,
            'hasAccess' => $user->ID > 0 && Otp::hasAccess($user),
            'groups'   => $user->ID > 0 ? array_values(array_intersect_key(Groups::names(), array_flip(Groups::activeGroupsOf($user->ID)))) : [],
            'logout'   => wp_nonce_url(add_query_arg(['action' => 'crnd_logout'], admin_url('admin-post.php')), 'crnd_logout'),
            'library'  => '',
        ];

        if ($vars['hasAccess']) {
            $libraryAtts = array_filter([
                'category' => (string) $atts['category'],
                'layout'   => (string) $atts['layout'],
                'title'    => (string) $atts['title'],
            ]);
            // The portal lists what this member may open; nothing locked.
            $libraryAtts['private'] = 'hide';
            if ($atts['category'] === '') {
                $libraryAtts['only'] = 'restricted';
            }
            $vars['library'] = Plugin::instance()->renderer->render($libraryAtts);
        }

        ob_start();
        TemplateLoader::include('portal.php', $vars, CRND_PRO_DIR . 'templates/');
        return (string) ob_get_clean();
    }

    public function handleRequest(): void
    {
        $this->checkNonce();
        $email = sanitize_email(wp_unslash($_POST['email'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        $ok    = $this->otp->request($email);
        if (!$ok) {
            $this->back('limit');
        }
        $this->startFlow($email);
        $this->back('sent');
    }

    public function handleVerify(): void
    {
        $this->checkNonce();
        $email = $this->flowEmail();
        if ($email === '') {
            $this->back('expired');
        }
        $code = sanitize_text_field(wp_unslash($_POST['code'] ?? '')); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        $user = $this->otp->verify($email, $code);
        if (is_wp_error($user)) {
            $this->back('invalid');
        }

        $this->endFlow();
        wp_clear_auth_cookie();
        wp_set_current_user($user->ID);
        wp_set_auth_cookie($user->ID, false, is_ssl());
        do_action('wp_login', $user->user_login, $user); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, so security plugins see the sign-in

        $redirect = wp_validate_redirect(esc_url_raw(wp_unslash($_POST['redirect_to'] ?? '')), ''); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        wp_safe_redirect($redirect !== '' ? $redirect : ($this->url() ?: home_url('/')));
        exit;
    }

    /** "Use another email": forget the email typed before. */
    public function handleRestart(): void
    {
        $this->checkNonce();
        $this->endFlow();
        $this->back('');
    }

    public function handleLogout(): void
    {
        check_admin_referer('crnd_logout');
        wp_logout();
        wp_safe_redirect(add_query_arg('crnd_msg', 'logged_out', $this->url() ?: home_url('/')));
        exit;
    }

    public function keepMembersOutOfAdmin(): void
    {
        global $pagenow;
        if (wp_doing_ajax() || $pagenow === 'admin-post.php' || !$this->isOnlyMember()) {
            return;
        }
        wp_safe_redirect($this->url() ?: home_url('/'));
        exit;
    }

    public function hideAdminBar(bool $show): bool
    {
        return $this->isOnlyMember() ? false : $show;
    }

    public function sessionLength(int $seconds, int $userId): int
    {
        $user = get_userdata($userId);
        if ($user instanceof \WP_User && in_array(Groups::MEMBER_ROLE, $user->roles, true) && !user_can($user, 'edit_crnd_documents')) {
            return max(1, (int) $this->settings->get('session_hours')) * HOUR_IN_SECONDS;
        }
        return $seconds;
    }

    public function loginRedirect(string $redirect, string $requested, \WP_User|\WP_Error $user): string
    {
        if ($user instanceof \WP_User && in_array(Groups::MEMBER_ROLE, $user->roles, true) && !user_can($user, 'edit_crnd_documents')) {
            return $requested !== '' && !str_contains($requested, '/wp-admin') ? $requested : ($this->url() ?: home_url('/'));
        }
        return $redirect;
    }

    /** A member and nothing more (an editor who is also a member keeps the admin). */
    private function isOnlyMember(): bool
    {
        $user = wp_get_current_user();
        return $user->ID > 0 && in_array(Groups::MEMBER_ROLE, $user->roles, true) && !user_can($user, 'edit_crnd_documents');
    }

    private function checkNonce(): void
    {
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce(sanitize_key(wp_unslash($_POST['_wpnonce'])), self::NONCE)) {
            $this->back('error');
        }
    }

    private function startFlow(string $email): void
    {
        $token = bin2hex(random_bytes(16));
        set_transient('crnd_flow_' . $token, strtolower(trim($email)), self::FLOW_TTL);
        setcookie(self::COOKIE, $token, [
            'expires'  => time() + self::FLOW_TTL,
            'path'     => COOKIEPATH,
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    private function flowEmail(): string
    {
        $token = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE])) : '';
        if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
            return '';
        }
        return (string) get_transient('crnd_flow_' . $token);
    }

    private function endFlow(): void
    {
        $token = isset($_COOKIE[self::COOKIE]) ? sanitize_key(wp_unslash($_COOKIE[self::COOKIE])) : '';
        if ($token !== '') {
            delete_transient('crnd_flow_' . $token);
        }
        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => COOKIEPATH]);
        unset($_COOKIE[self::COOKIE]);
    }

    /** Back to the page the form was on (keeping redirect_to), with a message code. */
    private function back(string $message): never
    {
        $referer  = wp_get_referer();
        $base     = $referer ? remove_query_arg(['crnd_msg'], $referer) : ($this->url() ?: home_url('/'));
        $location = $message !== '' ? add_query_arg('crnd_msg', $message, $base) : $base;
        wp_safe_redirect($location);
        exit;
    }
}
