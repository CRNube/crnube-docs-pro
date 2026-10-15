<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant;

use CRNube\DocsPro\Assistant\Providers\Anthropic;
use CRNube\DocsPro\Assistant\Providers\Gemini;
use CRNube\DocsPro\Assistant\Providers\OpenAi;
use CRNube\DocsPro\Assistant\Providers\Provider;

/**
 * Settings of the document assistant (option `crnd_ai_settings`).
 *
 * API keys and the Turnstile secret are stored encrypted (libsodium secretbox, with a
 * key derived from the site's salts), so a database copy alone does not reveal them.
 * They can also come from wp-config.php: CRND_AI_API_KEY, CRND_TURNSTILE_SECRET.
 */
final class Settings
{
    public const OPTION = 'crnd_ai_settings';

    public const PROVIDERS = ['anthropic', 'openai', 'gemini'];

    /**
     * Suggested models and their price in USD per million tokens [input, output]. The
     * admin can type any other model and its price; prices are what the spending caps use.
     */
    public const MODELS = [
        'anthropic' => [
            'claude-haiku-5-5'  => [0.10, 0.50],
            'claude-sonnet-5-5' => [2.00, 10.00],
            'claude-opus-5-5'   => [4.00, 20.00],
        ],
        'openai' => [
            'gpt-5.4-mini' => [0.75, 4.50],
        ],
        'gemini' => [
            'gemini-3.1-flash-lite' => [0.25, 1.50],
            'gemini-2.5-flash-lite' => [0.10, 0.40],
        ],
    ];

    /** @var array<string, mixed>|null */
    private static ?array $values = null;

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        $models = [];
        $prices = [];
        foreach (self::MODELS as $provider => $list) {
            $first             = (string) array_key_first($list);
            $models[$provider] = $first;
            $prices[$provider] = $list[$first];
        }
        return [
            'enabled'        => false,
            'provider'       => 'anthropic',
            'keys'           => [],
            'models'         => $models,
            'prices'         => $prices,
            'daily_budget'   => 2.0,
            'monthly_budget' => 30.0,
            'per_hour'       => 5,
            'per_day'        => 15,
            'max_answer'     => 700,
            'turnstile_site'   => '',
            'turnstile_secret' => '',
            'save_questions' => true,
            'retention_days' => 90,
            'title'          => '',
            'welcome'        => '',
            'suggestions'    => '',
        ];
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$values === null) {
            $stored       = get_option(self::OPTION, []);
            self::$values = array_replace_recursive(self::defaults(), is_array($stored) ? $stored : []);
        }
        return self::$values;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    public static function provider(): string
    {
        $provider = (string) self::get('provider');
        return in_array($provider, self::PROVIDERS, true) ? $provider : 'anthropic';
    }

    public static function model(): string
    {
        return (string) (self::get('models')[self::provider()] ?? '');
    }

    /** @return array{0: float, 1: float} USD per million tokens, input and output. */
    public static function prices(): array
    {
        $price = (array) (self::get('prices')[self::provider()] ?? [0, 0]);
        return [(float) ($price[0] ?? 0), (float) ($price[1] ?? 0)];
    }

    public static function apiKey(): string
    {
        if (defined('CRND_AI_API_KEY') && (string) constant('CRND_AI_API_KEY') !== '') {
            return (string) constant('CRND_AI_API_KEY');
        }
        return self::decrypt((string) (self::get('keys')[self::provider()] ?? ''));
    }

    public static function keyFromConfig(): bool
    {
        return defined('CRND_AI_API_KEY') && (string) constant('CRND_AI_API_KEY') !== '';
    }

    public static function turnstileSecret(): string
    {
        if (defined('CRND_TURNSTILE_SECRET') && (string) constant('CRND_TURNSTILE_SECRET') !== '') {
            return (string) constant('CRND_TURNSTILE_SECRET');
        }
        return self::decrypt((string) self::get('turnstile_secret'));
    }

    public static function turnstileEnabled(): bool
    {
        return (string) self::get('turnstile_site') !== '' && self::turnstileSecret() !== '';
    }

    /**
     * Configured well enough to answer: on, a key, a model, and prices (without prices
     * the spending caps could not work, so the assistant stays off).
     */
    public static function isReady(): bool
    {
        [$in, $out] = self::prices();
        return (bool) self::get('enabled') && self::apiKey() !== '' && self::model() !== '' && $in > 0 && $out > 0;
    }

    public static function client(): Provider
    {
        $key   = self::apiKey();
        $model = self::model();
        return match (self::provider()) {
            'openai' => new OpenAi($key, $model),
            'gemini' => new Gemini($key, $model),
            default  => new Anthropic($key, $model),
        };
    }

    /** Cost in millionths of a dollar. */
    public static function cost(int $inputTokens, int $outputTokens): int
    {
        [$in, $out] = self::prices();
        // Claude Haiku 5.5 charges more above 100K input tokens.
        if (self::model() === 'claude-haiku-5-5' && $inputTokens > 100000) {
            [$in, $out] = [0.50, 2.50];
        }
        return (int) ceil($inputTokens * $in + $outputTokens * $out);
    }

    /**
     * @param array<string, mixed> $input Raw form values.
     */
    public static function save(array $input): void
    {
        $current = self::all();
        $clean   = $current;

        $clean['enabled']  = !empty($input['enabled']);
        $provider          = sanitize_key((string) ($input['provider'] ?? ''));
        $clean['provider'] = in_array($provider, self::PROVIDERS, true) ? $provider : 'anthropic';

        foreach (self::PROVIDERS as $p) {
            $model = trim(sanitize_text_field((string) ($input['models'][$p] ?? '')));
            if (preg_match('/^[A-Za-z0-9._:\-\/]{1,100}$/', $model)) {
                $clean['models'][$p] = $model;
            }
            $clean['prices'][$p] = [
                max(0.0, min(1000.0, (float) ($input['prices'][$p][0] ?? 0))),
                max(0.0, min(1000.0, (float) ($input['prices'][$p][1] ?? 0))),
            ];
            // An empty field keeps the stored key; "-" removes it.
            $key = trim((string) ($input['keys'][$p] ?? ''));
            if ($key === '-') {
                unset($clean['keys'][$p]);
            } elseif ($key !== '' && preg_match('/^[\x21-\x7E]{8,300}$/', $key)) {
                $clean['keys'][$p] = self::encrypt($key);
            }
        }

        $clean['daily_budget']   = max(0.0, min(10000.0, round((float) ($input['daily_budget'] ?? 0), 2)));
        $clean['monthly_budget'] = max(0.0, min(100000.0, round((float) ($input['monthly_budget'] ?? 0), 2)));
        $clean['per_hour']       = max(1, min(100, (int) ($input['per_hour'] ?? 5)));
        $clean['per_day']        = max($clean['per_hour'], min(500, (int) ($input['per_day'] ?? 15)));
        $clean['max_answer']     = max(200, min(2000, (int) ($input['max_answer'] ?? 700)));
        $clean['save_questions'] = !empty($input['save_questions']);
        $clean['retention_days'] = max(7, min(730, (int) ($input['retention_days'] ?? 90)));
        $clean['turnstile_site'] = (string) preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($input['turnstile_site'] ?? ''));
        $secret                  = trim((string) ($input['turnstile_secret'] ?? ''));
        if ($secret === '-') {
            $clean['turnstile_secret'] = '';
        } elseif ($secret !== '' && preg_match('/^[\x21-\x7E]{8,200}$/', $secret)) {
            $clean['turnstile_secret'] = self::encrypt($secret);
        }
        $clean['title']       = sanitize_text_field((string) ($input['title'] ?? ''));
        $clean['welcome']     = sanitize_textarea_field((string) ($input['welcome'] ?? ''));
        $clean['suggestions'] = sanitize_textarea_field((string) ($input['suggestions'] ?? ''));

        update_option(self::OPTION, $clean, false);
        self::$values = null;
    }

    /** @return list<string> Up to four suggested questions. */
    public static function suggestions(): array
    {
        $lines = array_filter(array_map('trim', explode("\n", (string) self::get('suggestions'))));
        return array_slice(array_values($lines), 0, 4);
    }

    private static function secretKey(): string
    {
        return sodium_crypto_generichash('crnd-ai-settings|' . wp_salt('auth'), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    private static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'v1:' . sodium_bin2base64($nonce . sodium_crypto_secretbox($plain, $nonce, self::secretKey()), SODIUM_BASE64_VARIANT_ORIGINAL);
    }

    /** '' when empty or unreadable (the salts changed: the key must be typed again). */
    private static function decrypt(string $stored): string
    {
        if (!str_starts_with($stored, 'v1:')) {
            return '';
        }
        try {
            $raw   = sodium_base642bin(substr($stored, 3), SODIUM_BASE64_VARIANT_ORIGINAL);
            $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::secretKey());
            return is_string($plain) ? $plain : '';
        } catch (\SodiumException) {
            return '';
        }
    }

    /** Whether a key is stored for $provider (without decrypting it). */
    public static function hasKey(string $provider): bool
    {
        return !empty(self::get('keys')[$provider]);
    }

    public static function flush(): void
    {
        self::$values = null;
    }
}
