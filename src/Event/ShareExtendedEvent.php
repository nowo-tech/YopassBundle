<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a share's expiration and / or max reads were extended ({@see YopassEvents::SHARE_EXTENDED}).
 *
 * Zero-knowledge: the payload is metadata only (ids, users, dates, counters, payload kind). It never carries the
 * ciphertext, the decryption key, a password or any decrypted content, and it does not expose the
 * {@see SecureShare} entity.
 */
final class ShareExtendedEvent extends Event
{
    /**
     * Creates the event from the old and new limits of a share (never the ciphertext).
     */
    public function __construct(
        private readonly string $shareId,
        private readonly object $creator,
        private readonly ?UserInterface $extendedBy,
        private readonly DateTimeImmutable $oldExpiresAt,
        private readonly DateTimeImmutable $newExpiresAt,
        private readonly int $oldMaxReads,
        private readonly int $newMaxReads,
        private readonly string $payloadKind,
    ) {
    }

    /**
     * Builds the event from the share after the change and the limits before it.
     */
    public static function fromShare(SecureShare $share, DateTimeImmutable $oldExpiresAt, int $oldMaxReads, ?UserInterface $extendedBy = null): self
    {
        return new self(
            $share->getId(),
            $share->getCreator(),
            $extendedBy,
            $oldExpiresAt,
            $share->getExpiresAt(),
            $oldMaxReads,
            $share->getMaxReads(),
            $share->getPayloadKind(),
        );
    }

    /**
     * Returns the id of the extended share.
     */
    public function getShareId(): string
    {
        return $this->shareId;
    }

    /**
     * Returns the creator of the share (application user entity).
     */
    public function getCreator(): object
    {
        return $this->creator;
    }

    /**
     * Returns the user who extended the share, if known.
     */
    public function getExtendedBy(): ?UserInterface
    {
        return $this->extendedBy;
    }

    /**
     * Returns the expiration date before the extension.
     */
    public function getOldExpiresAt(): DateTimeImmutable
    {
        return $this->oldExpiresAt;
    }

    /**
     * Returns the expiration date after the extension.
     */
    public function getNewExpiresAt(): DateTimeImmutable
    {
        return $this->newExpiresAt;
    }

    /**
     * Returns the max reads before the extension.
     */
    public function getOldMaxReads(): int
    {
        return $this->oldMaxReads;
    }

    /**
     * Returns the max reads after the extension.
     */
    public function getNewMaxReads(): int
    {
        return $this->newMaxReads;
    }

    /**
     * Returns the payload kind ("text" or "file").
     */
    public function getPayloadKind(): string
    {
        return $this->payloadKind;
    }
}
