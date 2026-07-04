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
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: SecureShare::class)]
    #[ORM\JoinColumn(name: 'share_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    private SecureShare $share;

    #[ORM\Column(name: 'accessed_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $accessedAt;

    #[ORM\Column(name: 'read_number', type: Types::INTEGER)]
    private int $readNumber;

    #[ORM\Column(name: 'ip_address', length: 45, nullable: true)]
    private ?string $ipAddress;

    #[ORM\Column(name: 'user_agent', length: 512, nullable: true)]
    private ?string $userAgent;

    public function __construct(
        string $id,
        SecureShare $share,
        int $readNumber,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ) {
        $this->id          = $id;
        $this->share       = $share;
        $this->readNumber  = $readNumber;
        $this->ipAddress   = $ipAddress;
        $this->userAgent   = $userAgent;
        $this->accessedAt  = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShare(): SecureShare
    {
        return $this->share;
    }

    public function getAccessedAt(): DateTimeImmutable
    {
        return $this->accessedAt;
    }

    public function getReadNumber(): int
    {
        return $this->readNumber;
    }

    public function getIpAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }
}
