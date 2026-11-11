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
 * Dispatched after a single share has been deleted from the manage UI ({@see YopassEvents::SHARE_DELETED}).
 *
 * Bulk removals ("delete all", retention purge) dispatch {@see ShareBulkDeletedEvent} instead.
 *
 * Zero-knowledge: the payload is metadata only. It never carries the ciphertext, the decryption key, a password or
 * any decrypted content, and it does not expose the {@see SecureShare} entity.
 */
final class ShareDeletedEvent extends Event
{
    /**
     * Creates the event from share metadata (never the ciphertext).
     */
    public function __construct(
        private readonly string $shareId,
        private readonly object $creator,
        private readonly ?UserInterface $deletedBy,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly int $maxReads,
        private readonly int $readsLeft,
        private readonly string $payloadKind,
        private readonly ?DateTimeImmutable $revokedAt,
    ) {
    }

    /**
     * Copies the metadata of a share; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share, ?UserInterface $deletedBy = null, ?object $creator = null): self
    {
        return new self(
            $share->getId(),
            $creator ?? $share->getCreator(),
            $deletedBy,
            $share->getCreatedAt(),
            $share->getExpiresAt(),
            $share->getMaxReads(),
            $share->getReadsLeft(),
            $share->getPayloadKind(),
            $share->getRevokedAt(),
        );
    }

    /**
     * Returns the id of the deleted share.
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
     * Returns the user who deleted the share (may differ from the creator when a listener granted access), if known.
     */
    public function getDeletedBy(): ?UserInterface
    {
        return $this->deletedBy;
    }

    /**
     * Returns the creation date.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the expiration date.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Returns the maximum number of reads.
     */
    public function getMaxReads(): int
    {
        return $this->maxReads;
    }

    /**
     * Returns the reads left when the share was deleted.
     */
    public function getReadsLeft(): int
    {
        return $this->readsLeft;
    }

    /**
     * Returns the payload kind ("text" or "file").
     */
    public function getPayloadKind(): string
    {
        return $this->payloadKind;
    }

    /**
     * Returns the revocation date, or null when the share was not revoked.
     */
    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    /**
     * Returns the creator id (`getId()` as a string; null when it exposes none). Since 1.16.
     */
    public function getCreatorId(): ?string
    {
        return UserIdResolver::getId($this->creator);
    }

    /**
     * Returns the id of the user who deleted the share (null when unknown). Since 1.16.
     */
    public function getDeletedById(): ?string
    {
        return $this->deletedBy instanceof UserInterface ? UserIdResolver::getId($this->deletedBy) : null;
    }
}
