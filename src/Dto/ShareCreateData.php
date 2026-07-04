<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Dto;

use DateTimeImmutable;

/**
 * Metadata for storing a client-encrypted share (plaintext never hits the server).
 */
final class ShareCreateData
{
    public string $ciphertext = '';

    public string $expiresIn = '1h';

    public int $maxReads = 1;

    public string $payloadKind = 'text';

    /**
     * @param list<array{id: string, interval: string}> $expirationOptions
     */
    public function resolveExpiresAt(array $expirationOptions): DateTimeImmutable
    {
        foreach ($expirationOptions as $option) {
            if ($option['id'] === $this->expiresIn) {
                return new DateTimeImmutable('+' . ltrim($option['interval'], '+ '));
            }
        }

        $fallback = $expirationOptions[0];

        return new DateTimeImmutable('+' . ltrim($fallback['interval'], '+ '));
    }
}
