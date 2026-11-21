<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Share\ShareCommandsInterface;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched after a share was revoked from the manage UI ({@see YopassEvents::SHARE_REVOKED}).
 *
 * Zero-knowledge: the payload is metadata only. It never carries the ciphertext, the decryption key, a password or
 * any decrypted content, and it does not expose the {@see SecureShare} entity.
 */
final class ShareRevokedEvent extends Event
{
    /**
     * Creates the event from share metadata (never the ciphertext).
     */
    public function __construct(
        private readonly string $shareId,
        private readonly object $creator,
        private readonly ?UserInterface $revokedBy,
        private readonly DateTimeImmutable $revokedAt,
        private readonly string $payloadKind,
        private readonly ?string $revokedById = null,
    ) {
    }

    /**
     * Copies the metadata of a revoked share; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share, ?UserInterface $revokedBy = null, ?object $creator = null, ?string $revokedById = null): self
    {
        return new self(
            $share->getId(),
            $creator ?? $share->getCreator(),
            $revokedBy,
            $share->getRevokedAt() ?? new DateTimeImmutable(),
            $share->getPayloadKind(),
            $revokedById,
        );
    }

    /**
     * Returns the id of the revoked share.
     */
    public function getShareId(): string
    {
        return $this->shareId;
    }

    /**
     * Returns the creator: with the relation mapping, the application user entity **in the application's entity
     * manager** (since 1.19.0 a lazy reference built from the creator id when the bundle uses its own entity manager);
     * with `user_mapping: id`, a {@see CreatorId}. Prefer {@see self::getCreatorId()}.
     *
     * @deprecated since 2.1.0, use {@see self::getCreatorId()}; removed in 3.0
     */
    public function getCreator(): object
    {
        return $this->creator;
    }

    /**
     * Returns the user who revoked the share (may differ from the creator when a listener granted access), if known.
     * Null when the share was revoked through the id-based {@see ShareCommandsInterface}.
     *
     * @deprecated since 2.1.0, use {@see self::getRevokedById()}; removed in 3.0
     */
    public function getRevokedBy(): ?UserInterface
    {
        return $this->revokedBy;
    }

    /**
     * Returns the revocation date.
     */
    public function getRevokedAt(): DateTimeImmutable
    {
        return $this->revokedAt;
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
     * Returns the id of the user who revoked the share (null when unknown). Since 1.16; since 2.1.0 also the actor id
     * given to the id-based commands.
     */
    public function getRevokedById(): ?string
    {
        return $this->revokedById ?? ($this->revokedBy instanceof UserInterface ? UserIdResolver::getId($this->revokedBy) : null);
    }

    /**
     * When the share was revoked (its revocation date). Since 2.1.0.
     */
    public function getOccurredAt(): DateTimeImmutable
    {
        return $this->revokedAt;
    }
}
