<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Access;

/**
 * Sign-in with a one-time code sent by email, so members never need a password.
 *
 * - 6 digits from a CSPRNG, valid 10 minutes, at most 5 tries; only its HMAC is stored.
 * - At most one code per minute and 5 per hour for an email, and 20 per hour per IP.
 * - Whether an email has access is never revealed: every request gets the same answer.
 */
final class Otp
{
    public const META_CODE       = '_crnd_otp';
    public const META_LAST_LOGIN = '_crnd_last_login';

    private const TTL          = 10 * MINUTE_IN_SECONDS;
    private const MAX_ATTEMPTS = 5;

    /**
     * Sends a code if the email belongs to someone with access. Returns false only when
     * a rate limit was hit (the caller shows "wait a moment"), true otherwise.
     */
    public function request(string $email): bool
    {
        $email = strtolower(trim(sanitize_email($email)));
        if (!is_email($email)) {
            return true;
        }
        if (!$this->allow('mail1_' . $email, 1, MINUTE_IN_SECONDS)
            || !$this->allow('mail_' . $email, 5, HOUR_IN_SECONDS)
            || !$this->allow('ip_' . $this->ip(), 20, HOUR_IN_SECONDS)) {
            return false;
        }

        $user = get_user_by('email', $email);
        if (!$user instanceof \WP_User || !self::hasAccess($user)) {
            return true;
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        update_user_meta($user->ID, self::META_CODE, [
            'hash'     => $this->hash($code, $user->ID),
            'expires'  => time() + self::TTL,
            'attempts' => 0,
        ]);

        $site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        /* translators: %s: site name */
        $subject = sprintf(__('Your access code for %s', 'crnube-docs-pro'), $site);
        $message = sprintf(
            /* translators: 1: person's name, 2: the 6-digit code, 3: site name */
            __("Hello %1\$s,\n\nYour access code is:\n\n    %2\$s\n\nIt expires in 10 minutes and can be used once.\n\nIf you did not ask for it, ignore this email: nobody can enter without the code.\n\n%3\$s", 'crnube-docs-pro'),
            $user->display_name,
            $code,
            $site
        );

        /**
         * Lets the site change the email (for example to send HTML or use its brand).
         *
         * @param array{to: string, subject: string, message: string, headers: array<string>} $mail
         * @param \WP_User $user
         * @param string   $code
         */
        $mail = apply_filters('crnd_otp_email', [
            'to'      => $user->user_email,
            'subject' => $subject,
            'message' => $message,
            'headers' => [],
        ], $user, $code);

        wp_mail($mail['to'], $mail['subject'], $mail['message'], $mail['headers']);
        return true;
    }

    /**
     * Checks a code. On success the code is used up and the user is returned.
     */
    public function verify(string $email, string $code): \WP_User|\WP_Error
    {
        $invalid = new \WP_Error('crnd_otp_invalid', __('The code is not valid or has expired. Ask for a new one.', 'crnube-docs-pro'));

        $user = get_user_by('email', strtolower(trim(sanitize_email($email))));
        if (!$user instanceof \WP_User) {
            return $invalid;
        }
        $stored = get_user_meta($user->ID, self::META_CODE, true);
        if (!is_array($stored) || empty($stored['hash']) || (int) $stored['expires'] < time()) {
            delete_user_meta($user->ID, self::META_CODE);
            return $invalid;
        }

        $code = preg_replace('/\D/', '', $code) ?? '';
        if (!hash_equals((string) $stored['hash'], $this->hash($code, $user->ID))) {
            $stored['attempts'] = (int) $stored['attempts'] + 1;
            if ($stored['attempts'] >= self::MAX_ATTEMPTS) {
                delete_user_meta($user->ID, self::META_CODE);
            } else {
                update_user_meta($user->ID, self::META_CODE, $stored);
            }
            return $invalid;
        }

        delete_user_meta($user->ID, self::META_CODE);
        if (!self::hasAccess($user)) {
            return $invalid;
        }
        update_user_meta($user->ID, self::META_LAST_LOGIN, wp_date('Y-m-d H:i'));
        return $user;
    }

    /** Members with an active membership, and the staff who manage documents. */
    public static function hasAccess(\WP_User $user): bool
    {
        return user_can($user, 'edit_crnd_documents') || Groups::activeGroupsOf($user->ID) !== [];
    }

    private function hash(string $code, int $userId): string
    {
        return hash_hmac('sha256', $userId . '|' . $code, wp_salt('auth'));
    }

    /** A sliding-window counter in a transient; false once $max is reached. */
    private function allow(string $key, int $max, int $window): bool
    {
        $name  = 'crnd_rl_' . md5($key);
        $count = (int) get_transient($name);
        if ($count >= $max) {
            return false;
        }
        set_transient($name, $count + 1, $window);
        return true;
    }

    private function ip(): string
    {
        return (string) apply_filters('crnd_visitor_ip', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? '')));
    }
}
