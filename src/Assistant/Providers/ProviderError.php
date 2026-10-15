<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * A failed call. `retryable` is true for rate limits, overload and network errors.
 * Messages are plain text, escaped wherever they are shown (admin notices).
 *
 * phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped
 */
final class ProviderError extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable = false, public readonly int $status = 0)
    {
        parent::__construct($message);
    }

    /**
     * Common handling of the HTTP answer: network error, status, JSON body.
     *
     * @param array<string, mixed>|\WP_Error $response
     * @return array<string, mixed> The decoded body of a 2xx answer.
     */
    public static function check(array|\WP_Error $response, string $provider): array
    {
        if (is_wp_error($response)) {
            throw new self($provider . ': ' . $response->get_error_message(), true);
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $body   = json_decode((string) wp_remote_retrieve_body($response), true);
        if ($status >= 200 && $status < 300 && is_array($body)) {
            return $body;
        }
        // The provider's own message helps the admin (wrong key, unknown model); it never contains the key.
        $message = is_array($body) ? (string) ($body['error']['message'] ?? $body['message'] ?? '') : '';
        throw new self(
            sprintf('%s: HTTP %d %s', $provider, $status, substr(wp_strip_all_tags($message), 0, 300)),
            $status === 429 || $status === 408 || $status === 529 || $status >= 500,
            $status
        );
    }
}
