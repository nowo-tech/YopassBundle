<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Repository\ExpirationExtendingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\MaxReadsRaisingShareRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use function in_array;
use function ltrim;

/**
 * Extends expiration and max-read limits for existing shares (creator-only).
 *
 * Application code: since 2.1.0 call {@see ShareCommandsInterface::extend()} (by share id).
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
        private ?HostCreatorReference $hostCreators = null,
        private ?ShareWriteUnit $writeUnit = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Extends the expiration and/or max reads of a share; throws ShareExtendException when nothing valid changes.
     * Dispatches {@see YopassEvents::SHARE_EXTENDED} after the flush.
     *
     * 1.21.0 (REQ-RUNTIME-003): with the bundle's write unit (Doctrine ORM driver) the max reads raise and the new
     * expiration commit together, or not at all: a refused or failed expiration rolls the raise back. With a repository
     * that implements {@see ExpirationExtendingShareRepositoryInterface} the expiration only moves forward (two
     * concurrent extensions keep the later one; a share revoked meanwhile is refused with "revoked", one deleted
     * meanwhile with "not_found" and no event, 2.1.1).
     *
     * @param string|null $extendedById 2.1.0: the acting user's id when no security user is known (id-based commands)
     */
    public function extend(SecureShare $share, ?string $expiresIn, ?int $maxReads, ?UserInterface $extendedBy = null, ?string $extendedById = null): void
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

        $write = function () use ($share, $raiser, $atomicRaise, $newExpiresAt): void {
            // JS-L1: the raise is one conditional UPDATE (reads_left + delta), so concurrent consumes are never given
            // back; it runs first, so a share revoked meanwhile is not extended at all.
            if ($raiser !== null && $atomicRaise !== null && !$raiser->raiseMaxReads($share, $atomicRaise)) {
                throw $this->refused($share, 'max_reads_not_increased');
            }

            if ($newExpiresAt instanceof DateTimeImmutable) {
                $this->applyExpiration($share, $newExpiresAt);
            }

            $this->shareRepository->persist($share);
            $this->shareRepository->flush();
        };
        if ($this->writeUnit instanceof ShareWriteUnit) {
            $this->writeUnit->run($write);
        } else {
            $write();
        }

        // Zero-knowledge: old / new limits only, never the ciphertext.
        $this->eventDispatcher?->dispatch(
            ShareExtendedEvent::fromShare($share, $oldExpiresAt, $oldMaxReads, $extendedBy, $this->hostCreators?->forHost($share->getCreator()), $extendedById, $this->now()),
            YopassEvents::SHARE_EXTENDED,
        );
    }

    /**
     * The new expiration: one conditional write with a repository that supports it (1.21.0), else on the entity.
     */
    private function applyExpiration(SecureShare $share, DateTimeImmutable $expiresAt): void
    {
        if (!$this->shareRepository instanceof ExpirationExtendingShareRepositoryInterface) {
            $share->extendExpiration($expiresAt);

            return;
        }

        if (!$this->shareRepository->extendExpiration($share, $expiresAt)) {
            throw $this->refused($share, 'expiration_not_extended');
        }
    }

    /**
     * Why a conditional write matched nothing (2.1.1, audit YD1-L1): `not_found` when the share was deleted meanwhile
     * (so no view and no event for a gone share), `revoked` when it was revoked, else `$otherwise`. The share is read
     * again first, which also refreshes a managed instance.
     */
    private function refused(SecureShare $share, string $otherwise): ShareExtendException
    {
        $stored = $this->shareRepository->find($share->getId());
        if (!$stored instanceof SecureShare) {
            return new ShareExtendException('not_found');
        }

        return new ShareExtendException($stored->getRevokedAt() instanceof DateTimeImmutable ? 'revoked' : $otherwise);
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
