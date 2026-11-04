<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * In-memory PSR logger used to assert that no record carries secret material.
 */
final class SecretCapturingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}
