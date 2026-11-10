<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after one read of a share was consumed through its public link ({@see YopassEvents::SHARE_CONSUMED}).
 * Since 1.17.
 *
 * Fired once the read is stored (the atomic `consumeReadIfAvailable()` returned the share), never for a refused read
 * (expired, revoked, exhausted, unknown id).
 *
 * Ids and counters only: no ciphertext, key, password or decrypted content, no entity, and no reader IP address or
 * user agent (the public link is anonymous; the optional Doctrine ORM access log keeps those).
 */
final class ShareConsumedEvent extends Event
{
    /**
     * Creates the event from scalar share metadata.
     */
    public function __construct(
        private readonly string $shareId,
        private readonly ?string $creatorId,
        private readonly int $maxReads,
        private readonly int $readsLeft,
        private readonly DateTimeImmutable $consumedAt,
        private readonly string $payloadKind,
    ) {
    }

    /**
     * Copies the metadata of a share right after a read was consumed; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share, DateTimeImmutable $consumedAt): self
    {
        return new self(
            $share->getId(),
            $share->getCreatorId(),
            $share->getMaxReads(),
            $share->getReadsLeft(),
            $consumedAt,
            $share->getPayloadKind(),
        );
    }

    /**
     * Returns the id of the consumed share.
     */
    public function getShareId(): string
    {
        return $this->shareId;
    }

    /**
     * Returns the creator id (`getId()` as a string; null when the creator exposes none).
     */
    public function getCreatorId(): ?string
    {
        return $this->creatorId;
    }

    /**
     * Returns the read number this consumption was (1 for the first read).
     */
    public function getReadNumber(): int
    {
        return $this->maxReads - $this->readsLeft;
    }

    /**
     * Returns the maximum number of reads of the share.
     */
    public function getMaxReads(): int
    {
        return $this->maxReads;
    }

    /**
     * Returns the reads left after this one (0 = the link is now used up).
     */
    public function getReadsLeft(): int
    {
        return $this->readsLeft;
    }

    /**
     * Returns when the read was consumed.
     */
    public function getConsumedAt(): DateTimeImmutable
    {
        return $this->consumedAt;
    }

    /**
     * Returns the payload kind ("text" or "file").
     */
    public function getPayloadKind(): string
    {
        return $this->payloadKind;
    }
}
