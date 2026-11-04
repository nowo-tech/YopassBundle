<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
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
    ) {
    }

    /**
     * Copies the metadata of a revoked share; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share, ?UserInterface $revokedBy = null): self
    {
        return new self(
            $share->getId(),
            $share->getCreator(),
            $revokedBy,
            $share->getRevokedAt() ?? new DateTimeImmutable(),
            $share->getPayloadKind(),
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
     * Returns the creator of the share (application user entity).
     */
    public function getCreator(): object
    {
        return $this->creator;
    }

    /**
     * Returns the user who revoked the share (may differ from the creator when a listener granted access), if known.
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
}
