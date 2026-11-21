<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Read model of one share (2.1.0, REQ-ARCH-001 rule 1): scalars and dates only, never the ciphertext, a key, a
 * password or any decrypted content, and no entity or framework type. Returned by {@see ShareQueryInterface} and
 * {@see ShareCommandsInterface}.
 *
 * `state` is computed when the view is built: `revoked`, `expired`, `consumed` (no reads left) or `active`.
 */
final readonly class ShareView
{
    public const STATE_ACTIVE = 'active';

    public const STATE_EXPIRED = 'expired';

    public const STATE_REVOKED = 'revoked';

    public const STATE_CONSUMED = 'consumed';

    /** @var self::STATE_* */
    public string $state;

    /**
     * @param string $id the share id
     * @param string|null $creatorId the creator's id (`getId()` as a string, or the stored id with `user_mapping: id`); null when the creator exposes none
     * @param DateTimeImmutable $now the instant the state is computed for
     */
    public function __construct(
        public string $id,
        public ?string $creatorId,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $expiresAt,
        public int $maxReads,
        public int $readsLeft,
        public string $payloadKind,
        public ?DateTimeImmutable $revokedAt,
        DateTimeImmutable $now,
    ) {
        $this->state = match (true) {
            $revokedAt instanceof DateTimeImmutable => self::STATE_REVOKED,
            $expiresAt <= $now                      => self::STATE_EXPIRED,
            $readsLeft <= 0                         => self::STATE_CONSUMED,
            default                                 => self::STATE_ACTIVE,
        };
    }

    /**
     * Copies the metadata of a share; the ciphertext is deliberately left out.
     */
    public static function fromShare(SecureShare $share, DateTimeImmutable $now): self
    {
        return new self(
            $share->getId(),
            $share->getCreatorId(),
            $share->getCreatedAt(),
            $share->getExpiresAt(),
            $share->getMaxReads(),
            $share->getReadsLeft(),
            $share->getPayloadKind(),
            $share->getRevokedAt(),
            $now,
        );
    }

    /**
     * Whether the link can still be read (not revoked, not expired, reads left) at the instant the view was built.
     */
    public function isActive(): bool
    {
        return $this->state === self::STATE_ACTIVE;
    }
}
