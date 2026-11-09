<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function hash;

/**
 * Records a successful public consume (link open) for a secure share.
 *
 * The read audit trail outlives its share (1.16, owner decision): deleting a share (or its creator, which deletes the
 * creator's shares) sets `share_id` to NULL and keeps the row; `share_ref` (SHA-256 of the share id) keeps the rows of
 * one share together and lets an auditor match a known share id, without storing the link path itself.
 *
 * Table name is configured via SecureShareMetadataListener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'yopass_share_access_logs')]
class ShareAccessLog
{
    #[ORM\Column(name: 'accessed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $accessedAt;

    /** The share; null once the share was deleted (the row stays). */
    #[ORM\ManyToOne(targetEntity: SecureShare::class)]
    #[ORM\JoinColumn(name: 'share_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    private ?SecureShare $share; // @phpstan-ignore property.unusedType (Doctrine hydrates NULL after the share is deleted)

    /** SHA-256 (hex) of the share id; null only on rows written before 1.16 and not backfilled. */
    #[ORM\Column(name: 'share_ref', type: Types::STRING, length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $shareRef; // @phpstan-ignore property.unusedType (Doctrine hydrates NULL on pre-1.16 rows)

    /**
     * Creates an access log entry for a share read, stamped with the access time.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        SecureShare $share,
        #[ORM\Column(name: 'read_number', type: Types::INTEGER)]
        private int $readNumber,
        #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
        private ?string $ipAddress = null,
        #[ORM\Column(name: 'user_agent', length: 512, nullable: true)]
        private ?string $userAgent = null,
    ) {
        $this->share      = $share;
        $this->shareRef   = self::referenceFor($share->getId());
        $this->accessedAt = new DateTimeImmutable();
    }

    /**
     * The non-identifying share reference stored on the log rows: SHA-256 (hex) of the share id.
     */
    public static function referenceFor(string $shareId): string
    {
        return hash('sha256', $shareId);
    }

    /**
     * Returns the identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the share that was opened, or null once it was deleted (1.16: the row outlives its share).
     */
    public function getShare(): ?SecureShare
    {
        return $this->share;
    }

    /**
     * Returns the share reference (SHA-256 of the share id), null on a pre-1.16 row that was not backfilled.
     */
    public function getShareRef(): ?string
    {
        return $this->shareRef;
    }

    /**
     * Returns the access date.
     */
    public function getAccessedAt(): DateTimeImmutable
    {
        return $this->accessedAt;
    }

    /**
     * Returns the sequence number of this read for the share.
     */
    public function getReadNumber(): int
    {
        return $this->readNumber;
    }

    /**
     * Returns the client IP address, if recorded.
     */
    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    /**
     * Returns the client user agent, if recorded.
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
