<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a share has been persisted ({@see YopassEvents::SHARE_CREATED}).
 *
 * Zero-knowledge: the payload is metadata only (id, creator, dates, read limit, payload kind). It never carries the
 * ciphertext, the decryption key, a password or any decrypted content, and it does not expose the {@see SecureShare}
 * entity (which holds the ciphertext), so listeners cannot log or forward secret material by accident.
 */
final class ShareCreatedEvent extends Event
{
    /**
     * Creates the event from share metadata (never the ciphertext).
     */
    public function __construct(
        private readonly string $shareId,
        private readonly object $creator,
        private readonly DateTimeImmutable $createdAt,
        private readonly DateTimeImmutable $expiresAt,
        private readonly int $maxReads,
        private readonly string $payloadKind,
    ) {
    }

    /**
     * Copies the metadata of a share; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share): self
    {
        return new self(
            $share->getId(),
            $share->getCreator(),
            $share->getCreatedAt(),
            $share->getExpiresAt(),
            $share->getMaxReads(),
            $share->getPayloadKind(),
        );
    }

    /**
     * Returns the share id.
     */
    public function getShareId(): string
    {
        return $this->shareId;
    }

    /**
     * Returns the creator (application user entity).
     */
    public function getCreator(): object
    {
        return $this->creator;
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
}
