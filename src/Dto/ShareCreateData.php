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
     * Resolves the absolute expiration date for the selected expiration option.
     *
     * Falls back to the first configured option when the submitted id is unknown.
     *
     * @param list<array{id: string, interval: string}> $expirationOptions
     * @param DateTimeImmutable|null $now Reference time (inject a clock in services; defaults to the current time)
     *
     * @return DateTimeImmutable Expiration date relative to $now
     */
    public function resolveExpiresAt(array $expirationOptions, ?DateTimeImmutable $now = null): DateTimeImmutable
    {
        $now ??= new DateTimeImmutable();

        foreach ($expirationOptions as $option) {
            if ($option['id'] === $this->expiresIn) {
                return $now->modify('+' . ltrim($option['interval'], '+ '));
            }
        }

        $fallback = $expirationOptions[0];

        return $now->modify('+' . ltrim($fallback['interval'], '+ '));
    }
}
