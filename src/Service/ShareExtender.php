<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

use function in_array;
use function ltrim;

/**
 * Extends expiration and max-read limits for existing shares (creator-only).
 */
final class ShareExtender
{
    /**
     * @param array{
     *     expiration_options: list<array{id: string, interval: string}>,
     *     max_reads_options: list<int>
     * } $shareOptions
     */
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
        private readonly array $shareOptions,
    ) {
    }

    public function extend(SecureShare $share, ?string $expiresIn, ?int $maxReads): void
    {
        if ($share->getRevokedAt() !== null) {
            throw new ShareExtendException('revoked');
        }

        $changed = false;

        if ($expiresIn !== null && $expiresIn !== '') {
            $this->extendExpiration($share, $expiresIn);
            $changed = true;
        }

        if ($maxReads !== null && $maxReads > 0) {
            $this->extendMaxReads($share, $maxReads);
            $changed = true;
        }

        if (!$changed) {
            throw new ShareExtendException('nothing_to_extend');
        }

        $this->shareRepository->persist($share);
        $this->shareRepository->flush();
    }

    private function extendExpiration(SecureShare $share, string $expiresIn): void
    {
        $interval = $this->findExpirationInterval($expiresIn);

        if ($interval === null) {
            throw new ShareExtendException('invalid_expiration');
        }

        $now  = new DateTimeImmutable();
        $base = $share->getExpiresAt() > $now ? $share->getExpiresAt() : $now;
        $new  = $base->modify('+' . ltrim($interval, '+ '));

        if ($new <= $share->getExpiresAt()) {
            throw new ShareExtendException('expiration_not_extended');
        }

        $share->extendExpiration($new);
    }

    private function findExpirationInterval(string $expiresIn): ?string
    {
        foreach ($this->shareOptions['expiration_options'] as $option) {
            if ($option['id'] === $expiresIn) {
                return $option['interval'];
            }
        }

        return null;
    }

    private function extendMaxReads(SecureShare $share, int $maxReads): void
    {
        if (!in_array($maxReads, $this->shareOptions['max_reads_options'], true)) {
            throw new ShareExtendException('invalid_max_reads');
        }

        if ($maxReads <= $share->getMaxReads()) {
            throw new ShareExtendException('max_reads_not_increased');
        }

        $share->extendMaxReads($maxReads);
    }
}
