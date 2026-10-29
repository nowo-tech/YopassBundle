<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Records a successful public consume (link open) for a secure share.
 *
 * Table name is configured via SecureShareMetadataListener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'yopass_share_access_logs')]
class ShareAccessLog
{
    #[ORM\Column(name: 'accessed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $accessedAt;

    /**
     * Creates an access log entry for a share read, stamped with the access time.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,
        #[ORM\ManyToOne(targetEntity: SecureShare::class)]
        #[ORM\JoinColumn(name: 'share_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        private SecureShare $share,
        #[ORM\Column(name: 'read_number', type: Types::INTEGER)]
        private int $readNumber,
        #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
        private ?string $ipAddress = null,
        #[ORM\Column(name: 'user_agent', length: 512, nullable: true)]
        private ?string $userAgent = null,
    ) {
        $this->accessedAt = new DateTimeImmutable();
    }

    /**
     * Returns the identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the share being checked.
     */
    public function getShare(): SecureShare
    {
        return $this->share;
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
