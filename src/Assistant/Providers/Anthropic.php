<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * Claude, through the Messages API (raw HTTP: plugins cannot ship Composer
 * dependencies to sites without Node or Composer).
 */
final class Anthropic implements Provider
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    /** Models that can fall back to another Claude model when a safety classifier declines. */
    private const FALLBACK_MODELS = ['claude-fable-5-1', 'claude-opus-5-5', 'claude-opus-5', 'claude-sonnet-5-5'];

    public function __construct(private readonly string $apiKey, private readonly string $model)
    {
    }

    public function id(): string
    {
        return 'anthropic';
    }

    public function complete(string $system, array $messages, int $maxTokens, int $timeout): Result
    {
        $turns = [];
        foreach ($messages as $message) {
            $content = [];
            if (!empty($message['pdf'])) {
                $content[] = [
                    'type'   => 'document',
                    'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $message['pdf']],
                ];
            }
            $content[] = ['type' => 'text', 'text' => $message['text']];
            $turns[]   = ['role' => $message['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $content];
        }

        $body = [
            'model'         => $this->model,
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'messages'      => $turns,
            // Questions about documents need little reasoning; low effort keeps answers fast and cheap.
            'output_config' => ['effort' => 'low'],
        ];
        $headers = [
            'Content-Type'      => 'application/json',
            'x-api-key'         => $this->apiKey,
            'anthropic-version' => '2023-06-01',
        ];
        if (in_array($this->model, self::FALLBACK_MODELS, true)) {
            $body['fallbacks']         = 'default';
            $headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
        }

        $data = ProviderError::check(wp_remote_post(self::URL, [
            'timeout' => $timeout,
            'headers' => $headers,
            'body'    => (string) wp_json_encode($body),
        ]), 'Anthropic');

        $text = '';
        foreach ((array) ($data['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? '') === 'text') {
                $text .= (string) ($block['text'] ?? '');
            }
        }
        $usage = (array) ($data['usage'] ?? []);
        return new Result(
            $text,
            (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_creation_input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
            (int) ($usage['output_tokens'] ?? 0),
            ($data['stop_reason'] ?? '') === 'max_tokens',
            ($data['stop_reason'] ?? '') === 'refusal',
        );
    }
}
