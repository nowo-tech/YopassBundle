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

    /**
     * Creates a share for the given id and creator, stamped with the creation time.
     */
    public function __construct(#[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id, /** @var object Application user entity (nowo_yopass.user_class) */
        #[ORM\ManyToOne(targetEntity: UserInterface::class)]
        #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
        private object $creator)
    {
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Returns the identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the user who created the share.
     */
    public function getCreator(): object
    {
        return $this->creator;
    }

    /**
     * Returns the client-encrypted JSON envelope.
     */
    public function getCiphertext(): string
    {
        return $this->ciphertext;
    }

    /**
     * Sets the client-encrypted JSON envelope.
     */
    public function setCiphertext(string $ciphertext): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->ciphertext = $ciphertext;

        return $this;
    }

    /**
     * Returns the expiration date.
     */
    public function getExpiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * Sets the expiration date.
     */
    public function setExpiresAt(DateTimeImmutable $expiresAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Returns the maximum number of reads.
     */
    public function getMaxReads(): int
    {
        return $this->maxReads;
    }

    /**
     * Sets the maximum number of reads (also resets the remaining reads).
     */
    public function setMaxReads(int $maxReads): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->maxReads = $maxReads;
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->readsLeft = $maxReads;

        return $this;
    }

    /**
     * Returns the number of reads still available.
     */
    public function getReadsLeft(): int
    {
        return $this->readsLeft;
    }

    /**
     * Returns the creation date.
     */
    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns the revocation date, or null when the share is not revoked.
     */
    public function getRevokedAt(): ?DateTimeImmutable
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
     * Sets the payload kind ("text" or "file").
     */
    public function setPayloadKind(string $payloadKind): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->payloadKind = $payloadKind;

        return $this;
    }

    /**
     * Whether the share is not revoked, not expired and still has reads left.
     */
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

    /**
     * Decrements the remaining reads by one (never below zero).
     */
    public function consumeRead(): void
    {
        if ($this->readsLeft > 0) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            --$this->readsLeft;
        }
    }

    /**
     * Marks the share as revoked now and clears the remaining reads.
     */
    public function revoke(): void
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->revokedAt = new DateTimeImmutable();
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->readsLeft = 0;
    }

    /**
     * Moves the expiration date to a later value.
     */
    public function extendExpiration(DateTimeImmutable $expiresAt): self
    {
        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Sets a new max reads value; when it grows, the difference is added to the remaining reads.
     */
    public function extendMaxReads(int $maxReads): self
    {
        if ($maxReads > $this->maxReads) {
            // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
            $this->readsLeft += $maxReads - $this->maxReads;
        }

        // @igor-ignore - Doctrine entity field; instance-scoped, not a shared service.
        $this->maxReads = $maxReads;

        return $this;
    }
}
