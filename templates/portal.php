<?php
/**
 * Members' portal: sign-in with an emailed code, or the documents of the signed-in member.
 *
 * Override it by copying this file to {theme}/crnube-docs/portal.php.
 *
 * @var string        $message   '', 'sent', 'invalid', 'limit', 'error', 'logged_out', 'expired'
 * @var string        $redirect  Where to go after signing in (already validated).
 * @var string        $nonce     Nonce action for the forms.
 * @var string        $action    admin-post.php URL.
 * @var string        $step      'email' or 'code'.
 * @var \WP_User|null $user
 * @var bool          $hasAccess
 * @var list<string>  $groups    Names of the member's active groups.
 * @var string        $logout    Logout URL.
 * @var string        $library   Rendered documents (signed-in members only).
 */

defined('ABSPATH') || exit;

$crnd_messages = [
    'sent'       => ['info', __('If that email has access, we sent it a 6-digit code. Check your inbox (and the spam folder).', 'crnube-docs-pro')],
    'invalid'    => ['error', __('The code is not valid or has expired. Try again or ask for a new one.', 'crnube-docs-pro')],
    'limit'      => ['error', __('Too many requests. Wait a minute and try again.', 'crnube-docs-pro')],
    'error'      => ['error', __('The form expired. Please try again.', 'crnube-docs-pro')],
    'expired'    => ['error', __('The code request expired. Enter your email again.', 'crnube-docs-pro')],
    'logged_out' => ['info', __('You signed out.', 'crnube-docs-pro')],
];
$crnd_notice = $crnd_messages[$message] ?? null;
?>
<div class="crnd crnd-portal">
    <?php if ($user && $hasAccess) : ?>
        <div class="crnd-portal__bar">
            <span class="crnd-portal__lock" aria-hidden="true"><?php echo \CRNube\Docs\Frontend\Icons::get('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup ?></span>
            <div class="crnd-portal__who">
                <strong>
                    <?php
                    /* translators: %s: person's name */
                    echo esc_html(sprintf(__('Hello, %s', 'crnube-docs-pro'), $user->display_name));
                    ?>
                </strong>
                <?php if ($groups !== []) : ?>
                    <span><?php echo esc_html(implode(' · ', $groups)); ?></span>
                <?php endif; ?>
            </div>
            <a class="crnd-btn crnd-btn--ghost" href="<?php echo esc_url($logout); ?>"><?php esc_html_e('Sign out', 'crnube-docs-pro'); ?></a>
        </div>
        <?php echo $library; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered and escaped by the library templates ?>

    <?php elseif ($user) : ?>
        <div class="crnd-portal__card">
            <h2 class="crnd-portal__title"><?php esc_html_e('Your account has no current access', 'crnube-docs-pro'); ?></h2>
            <p><?php esc_html_e('Your membership may have expired. Contact the cooperative to renew it.', 'crnube-docs-pro'); ?></p>
            <a class="crnd-btn crnd-btn--ghost" href="<?php echo esc_url($logout); ?>"><?php esc_html_e('Sign out', 'crnube-docs-pro'); ?></a>
        </div>

    <?php else : ?>
        <div class="crnd-portal__card">
            <span class="crnd-portal__lock crnd-portal__lock--big" aria-hidden="true"><?php echo \CRNube\Docs\Frontend\Icons::get('lock'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed SVG markup ?></span>
            <h2 class="crnd-portal__title"><?php esc_html_e('Restricted documents', 'crnube-docs-pro'); ?></h2>

            <?php if ($crnd_notice) : ?>
                <p class="crnd-portal__notice crnd-portal__notice--<?php echo esc_attr($crnd_notice[0]); ?>" role="<?php echo $crnd_notice[0] === 'error' ? 'alert' : 'status'; ?>">
                    <?php echo esc_html($crnd_notice[1]); ?>
                </p>
            <?php endif; ?>

            <?php if ($step === 'code') : ?>
                <form class="crnd-portal__form" method="post" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="action" value="crnd_otp_verify">
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">
                    <?php wp_nonce_field($nonce); ?>
                    <label for="crnd-code"><?php esc_html_e('Access code', 'crnube-docs-pro'); ?></label>
                    <input id="crnd-code" class="crnd-portal__code" name="code" type="text" inputmode="numeric" autocomplete="one-time-code"
                        pattern="[0-9 ]{6,7}" maxlength="7" required placeholder="000000">
                    <button type="submit" class="crnd-btn crnd-btn--primary"><?php esc_html_e('Enter', 'crnube-docs-pro'); ?></button>
                </form>
                <form class="crnd-portal__alt" method="post" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="action" value="crnd_otp_restart">
                    <?php wp_nonce_field($nonce); ?>
                    <button type="submit" class="crnd-portal__link"><?php esc_html_e('Use another email or ask for a new code', 'crnube-docs-pro'); ?></button>
                </form>
            <?php else : ?>
                <p class="crnd-portal__intro"><?php esc_html_e('Enter the email registered with the cooperative and we will send you an access code. No password needed.', 'crnube-docs-pro'); ?></p>
                <form class="crnd-portal__form" method="post" action="<?php echo esc_url($action); ?>">
                    <input type="hidden" name="action" value="crnd_otp_request">
                    <?php wp_nonce_field($nonce); ?>
                    <label for="crnd-email"><?php esc_html_e('Email', 'crnube-docs-pro'); ?></label>
                    <input id="crnd-email" name="email" type="email" autocomplete="email" required>
                    <button type="submit" class="crnd-btn crnd-btn--primary"><?php esc_html_e('Send me a code', 'crnube-docs-pro'); ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
