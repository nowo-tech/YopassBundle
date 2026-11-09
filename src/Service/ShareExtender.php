<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\MaxReadsRaisingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function ltrim;

/**
 * Extends expiration and max-read limits for existing shares (creator-only).
 */
final readonly class ShareExtender
{
    private ClockInterface $clock;

    /**
     * Creates the extender with the repository, share options, an optional clock and an optional event dispatcher
     * ({@see YopassEvents::SHARE_EXTENDED} after a successful extension).
     *
     * @param array{
     *     expiration_options: list<array{id: string, interval: string}>,
     *     max_reads_options: list<int>
     * } $shareOptions
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private array $shareOptions,
        ?ClockInterface $clock = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Extends the expiration and/or max reads of a share; throws ShareExtendException when nothing valid changes.
     * Dispatches {@see YopassEvents::SHARE_EXTENDED} after the flush.
     */
    public function extend(SecureShare $share, ?string $expiresIn, ?int $maxReads, ?UserInterface $extendedBy = null): void
    {
        $oldExpiresAt = $share->getExpiresAt();
        $oldMaxReads  = $share->getMaxReads();

        if ($share->getRevokedAt() instanceof DateTimeImmutable) {
            throw new ShareExtendException('revoked');
        }

        $changed = false;

        $newExpiresAt = null;
        if ($expiresIn !== null && $expiresIn !== '') {
            $newExpiresAt = $this->newExpiration($share, $expiresIn);
            $changed      = true;
        }

        $atomicRaise = null;
        $raiser      = $this->shareRepository instanceof MaxReadsRaisingShareRepositoryInterface ? $this->shareRepository : null;
        if ($maxReads !== null && $maxReads > 0) {
            $this->assertMaxReadsRaise($share, $maxReads);
            if ($raiser !== null) {
                $atomicRaise = $maxReads;
            } else {
                $share->extendMaxReads($maxReads);
            }
            $changed = true;
        }

        if (!$changed) {
            throw new ShareExtendException('nothing_to_extend');
        }

        // JS-L1: the raise is one conditional UPDATE (reads_left + delta), so concurrent consumes are never given back;
        // it runs first, so a share revoked meanwhile is not extended at all.
        if ($raiser !== null && $atomicRaise !== null && !$raiser->raiseMaxReads($share, $atomicRaise)) {
            throw new ShareExtendException($share->getRevokedAt() instanceof DateTimeImmutable ? 'revoked' : 'max_reads_not_increased');
        }

        if ($newExpiresAt instanceof DateTimeImmutable) {
            $share->extendExpiration($newExpiresAt);
        }

        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        // Zero-knowledge: old / new limits only, never the ciphertext.
        $this->eventDispatcher?->dispatch(
            ShareExtendedEvent::fromShare($share, $oldExpiresAt, $oldMaxReads, $extendedBy),
            YopassEvents::SHARE_EXTENDED,
        );
    }

    private function newExpiration(SecureShare $share, string $expiresIn): DateTimeImmutable
    {
        $interval = $this->findExpirationInterval($expiresIn);

        if ($interval === null) {
            throw new ShareExtendException('invalid_expiration');
        }

        $now  = $this->now();
        $base = $share->getExpiresAt() > $now ? $share->getExpiresAt() : $now;
        $new  = $base->modify('+' . ltrim($interval, '+ '));

        if ($new <= $share->getExpiresAt()) {
            throw new ShareExtendException('expiration_not_extended');
        }

        return $new;
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

    private function assertMaxReadsRaise(SecureShare $share, int $maxReads): void
    {
        if (!in_array($maxReads, $this->shareOptions['max_reads_options'], true)) {
            throw new ShareExtendException('invalid_max_reads');
        }

        if ($maxReads <= $share->getMaxReads()) {
            throw new ShareExtendException('max_reads_not_increased');
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
