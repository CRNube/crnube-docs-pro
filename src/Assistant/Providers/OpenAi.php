<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * ChatGPT models, through the Chat Completions API.
 */
final class OpenAi implements Provider
{
    private const URL = 'https://api.openai.com/v1/chat/completions';

    public function __construct(private readonly string $apiKey, private readonly string $model)
    {
    }

    public function id(): string
    {
        return 'openai';
    }

    public function complete(string $system, array $messages, int $maxTokens, int $timeout): Result
    {
        $turns = [['role' => 'system', 'content' => $system]];
        foreach ($messages as $message) {
            if (!empty($message['pdf'])) {
                $turns[] = ['role' => 'user', 'content' => [
                    ['type' => 'file', 'file' => [
                        'filename'  => (string) ($message['filename'] ?? 'document.pdf'),
                        'file_data' => 'data:application/pdf;base64,' . $message['pdf'],
                    ]],
                    ['type' => 'text', 'text' => $message['text']],
                ]];
                continue;
            }
            $turns[] = ['role' => $message['role'] === 'assistant' ? 'assistant' : 'user', 'content' => $message['text']];
        }

        $data = ProviderError::check(wp_remote_post(self::URL, [
            'timeout' => $timeout,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->apiKey,
            ],
            'body'    => (string) wp_json_encode([
                'model'                 => $this->model,
                'messages'              => $turns,
                'max_completion_tokens' => $maxTokens,
            ]),
        ]), 'OpenAI');

        $choice = (array) ($data['choices'][0] ?? []);
        $usage  = (array) ($data['usage'] ?? []);
        $reason = (string) ($choice['finish_reason'] ?? '');
        return new Result(
            (string) ($choice['message']['content'] ?? ''),
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            $reason === 'length',
            $reason === 'content_filter' || !empty($choice['message']['refusal']),
        );
    }
}
