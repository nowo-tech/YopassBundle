<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Ephemeral encrypted secret (Yopass-style share).
 *
 * Table name and creator target entity are configured via SecureShareMetadataListener.
 */
#[ORM\Entity]
#[ORM\Table(name: 'yopass_secure_shares')]
class SecureShare
{
    #[ORM\Column(type: Types::TEXT)]
    private string $ciphertext = '';

    #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $expiresAt;

    #[ORM\Column(name: 'max_reads', type: Types::INTEGER)]
    private int $maxReads = 1;

    #[ORM\Column(name: 'reads_left', type: Types::INTEGER)]
    private int $readsLeft = 1;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'revoked_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ORM\Column(name: 'payload_kind', length: 16, options: ['default' => 'text'])]
    private string $payloadKind = 'text';

    public function __construct(#[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id, /** @var object Application user entity (nowo_yopass.user_class) */
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator)
    {
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getCreator(): object
    {
        return $this->creator;
    }

    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    public function setCiphertext(string $ciphertext): self
    {
        $this->ciphertext = $ciphertext;

        return $this;
    }

    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getMaxReads(): int
    {
        return $this->maxReads;
    }

    public function setMaxReads(int $maxReads): self
    {
        $this->maxReads  = $maxReads;
        $this->readsLeft = $maxReads;

        return $this;
    }

    public function getReadsLeft(): int
    {
        return $this->readsLeft;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getRevokedAt(): ?DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function getPayloadKind(): string
    {
        return $this->payloadKind;
    }

    public function setPayloadKind(string $payloadKind): self
    {
        $this->payloadKind = $payloadKind;

        return $this;
    }

    public function isAvailable(): bool
    {
        if ($this->revokedAt instanceof DateTimeImmutable) {
            return false;
        }

        if ($this->readsLeft <= 0) {
            return false;
        }

        return $this->expiresAt > new DateTimeImmutable();
    }

    public function consumeRead(): void
    {
        if ($this->readsLeft > 0) {
            --$this->readsLeft;
        }
    }

    public function revoke(): void
    {
        $this->revokedAt = new DateTimeImmutable();
        $this->readsLeft = 0;
    }

    public function extendExpiration(DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function extendMaxReads(int $maxReads): self
    {
        if ($maxReads > $this->maxReads) {
            $this->readsLeft += $maxReads - $this->maxReads;
        }

        $this->maxReads = $maxReads;

        return $this;
    }
}
