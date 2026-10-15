<?php
declare(strict_types=1);

namespace CRNube\DocsPro\Assistant\Providers;

/**
 * One answer from a provider, in the same shape whatever the provider.
 */
final class Result
{
    /**
     * @param bool $truncated The answer stopped at the output limit (more can be asked for).
     * @param bool $refused   The provider declined to answer.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $truncated = false,
        public readonly bool $refused = false,
    ) {
    }
}
