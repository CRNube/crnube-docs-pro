<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * Google Gemini models, through the generateContent API.
 */
final class Gemini implements Provider
{
    private const URL = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function __construct(private readonly string $apiKey, private readonly string $model)
    {
    }

    public function id(): string
    {
        return 'gemini';
    }

    public function complete(string $system, array $messages, int $maxTokens, int $timeout): Result
    {
        $contents = [];
        foreach ($messages as $message) {
            $parts = [];
            if (!empty($message['pdf'])) {
                $parts[] = ['inlineData' => ['mimeType' => 'application/pdf', 'data' => $message['pdf']]];
            }
            $parts[]    = ['text' => $message['text']];
            $contents[] = ['role' => $message['role'] === 'assistant' ? 'model' : 'user', 'parts' => $parts];
        }

        $data = ProviderError::check(wp_remote_post(sprintf(self::URL, rawurlencode($this->model)), [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'   => 'application/json',
                // In a header, not the URL, so the key never lands in server logs.
                'x-goog-api-key' => $this->apiKey,
            ],
            'body'    => (string) wp_json_encode([
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents'          => $contents,
                'generationConfig'  => ['maxOutputTokens' => $maxTokens],
            ]),
        ]), 'Gemini');

        $candidate = (array) ($data['candidates'][0] ?? []);
        $text      = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && empty($part['thought'])) {
                $text .= (string) ($part['text'] ?? '');
            }
        }
        $usage  = (array) ($data['usageMetadata'] ?? []);
        $reason = (string) ($candidate['finishReason'] ?? '');
        return new Result(
            $text,
            (int) ($usage['promptTokenCount'] ?? 0),
            // Thinking tokens are billed as output.
            (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0),
            $reason === 'MAX_TOKENS',
            in_array($reason, ['SAFETY', 'PROHIBITED_CONTENT', 'BLOCKLIST', 'RECITATION'], true) || isset($data['promptFeedback']['blockReason']),
        );
    }
}
