<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * An AI provider. Each one turns the same request into its own API call.
 *
 * Messages are plain turns: list of ['role' => 'user'|'assistant', 'text' => string],
 * where the first user turn may also carry a PDF (['pdf' => base64, 'filename' => …]).
 */
interface Provider
{
    /**
     * @param list<array{role: string, text: string, pdf?: string, filename?: string}> $messages
     * @throws ProviderError
     */
    public function complete(string $system, array $messages, int $maxTokens, int $timeout): Result;

    /** Short name for logs: "anthropic", "openai", "gemini". */
    public function id(): string;
}
