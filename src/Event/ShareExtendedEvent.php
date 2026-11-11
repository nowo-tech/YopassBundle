<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Support\UserIdResolver;
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
    public static function fromShare(SecureShare $share, DateTimeImmutable $oldExpiresAt, int $oldMaxReads, ?UserInterface $extendedBy = null, ?object $creator = null): self
    {
        return new self(
            $share->getId(),
            $creator ?? $share->getCreator(),
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
     * Returns the creator: with the relation mapping, the application user entity **in the application's entity
     * manager** (since 1.19.0 a lazy reference built from the creator id when the bundle uses its own entity manager);
     * with `user_mapping: id`, a {@see CreatorId}. Prefer {@see self::getCreatorId()}.
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

    /**
     * Returns the creator id (`getId()` as a string; null when it exposes none). Since 1.16.
     */
    public function getCreatorId(): ?string
    {
        return UserIdResolver::getId($this->creator);
    }

    /**
     * Returns the id of the user who extended the share (null when unknown). Since 1.16.
     */
    public function getExtendedById(): ?string
    {
        return $this->extendedBy instanceof UserInterface ? UserIdResolver::getId($this->extendedBy) : null;
    }
}
