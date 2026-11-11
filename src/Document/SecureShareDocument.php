<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function max;

/**
 * MongoDB document mapped to the same logical schema as {@see SecureShare}.
 */
#[ODM\Document]
#[ODM\Index(keys: ['createdAt' => 'asc'])]
#[ODM\Index(keys: ['expiresAt' => 'asc'])]
class SecureShareDocument
{
    #[ODM\Field(type: 'string')]
    private string $ciphertext = '';

    #[ODM\Field(type: 'date_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ODM\Field(type: 'int')]
    private int $maxReads = 1;

    /**
     * Written as `$inc (new - loaded)` on update, so a max-reads raise commutes with concurrent consumes (JS-L1).
     */
    #[ODM\Field(type: 'int', strategy: 'increment')]
    private int $readsLeft = 1;

    #[ODM\Field(type: 'date_immutable')]
    private DateTimeImmutable $createdAt;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ODM\Field(type: 'string')]
    private string $payloadKind = 'text';

    /**
     * Creates a share document for the given id and creator, stamped with the creation time.
     */
    public function __construct(#[ODM\Id(type: 'string', strategy: 'NONE')]
        private string $id, /** Application user document (nowo_yopass.user_class), or its id with the id mapping (1.18.0) */
        #[ODM\ReferenceOne(storeAs: 'id', targetDocument: UserInterface::class)]
        private object|string $creator)
    {
        // A CreatorId (id mapping) is stored as its plain id.
        $this->creator   = is_string($creator) ? $creator : CreatorId::unwrap($creator);
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * Creates the document for a new share, copying every field (including the creation and revocation dates) as is.
     */
    public static function fromShare(SecureShare $share): self
    {
        $document            = new self($share->getId(), $share->getCreator());
        $document->createdAt = $share->getCreatedAt();

        return $document->syncFrom($share);
    }

    /**
     * Copies the mutable state of a share onto this document.
     *
     * Revocation is one-way: the stored revocation date is never cleared or moved. A document loaded already revoked
     * also keeps zero reads; a stale managed copy (loaded before another worker revoked the share) may store a
     * non-zero `readsLeft` on the revoked document, which is harmless: consuming requires `revokedAt` to be null, and
     * {@see toShare()} never returns a negative count.
     */
    public function syncFrom(SecureShare $share): self
    {
        // @igor-ignore - Not shared worker service state.
        $this->ciphertext = $share->getCiphertext();
        // @igor-ignore - Not shared worker service state.
        $this->expiresAt = $share->getExpiresAt();
        // @igor-ignore - Not shared worker service state.
        $this->maxReads = $share->getMaxReads();
        // @igor-ignore - Not shared worker service state.
        $this->payloadKind = $share->getPayloadKind();

        if ($this->revokedAt instanceof DateTimeImmutable) {
            // @igor-ignore - Not shared worker service state.
            $this->readsLeft = 0;

            return $this;
        }

        // @igor-ignore - Not shared worker service state.
        $this->readsLeft = $share->getReadsLeft();
        // @igor-ignore - Not shared worker service state.
        $this->revokedAt = $share->getRevokedAt();

        return $this;
    }

    /**
     * Rebuilds the domain share from this document, every stored value unchanged.
     */
    public function toShare(): SecureShare
    {
        return SecureShare::reconstitute(
            $this->id,
            $this->creator,
            $this->ciphertext,
            $this->createdAt,
            $this->expiresAt,
            $this->maxReads,
            // `readsLeft` is written as an increment: a revoke from a stale copy may leave it below zero.
            max(0, $this->readsLeft),
            $this->revokedAt,
            $this->payloadKind,
        );
    }

    /**
     * Returns the identifier.
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Returns the user who created the share (a {@see CreatorId} with the id mapping, 1.18.0).
     */
    public function getCreator(): object
    {
        return CreatorId::wrap($this->creator);
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
        // @igor-ignore - Not shared worker service state.
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
        // @igor-ignore - Not shared worker service state.
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
        // @igor-ignore - Not shared worker service state.
        $this->maxReads = $maxReads;
        // @igor-ignore - Not shared worker service state.
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
        // @igor-ignore - Not shared worker service state.
        $this->payloadKind = $payloadKind;

        return $this;
    }

    /**
     * Decrements the remaining reads by one (never below zero).
     */
    public function consumeRead(): void
    {
        if ($this->readsLeft > 0) {
            // @igor-ignore - Not shared worker service state.
            --$this->readsLeft;
        }
    }

    /**
     * Marks the share as revoked now and clears the remaining reads.
     */
    public function revoke(): void
    {
        // @igor-ignore - Not shared worker service state.
        $this->revokedAt = new DateTimeImmutable();
        // @igor-ignore - Not shared worker service state.
        $this->readsLeft = 0;
    }

    /**
     * Moves the expiration date to a later value.
     */
    public function extendExpiration(DateTimeImmutable $expiresAt): self
    {
        // @igor-ignore - Not shared worker service state.
        $this->expiresAt = $expiresAt;

        return $this;
    }

    /**
     * Sets a new max reads value; when it grows, the difference is added to the remaining reads.
     */
    public function extendMaxReads(int $maxReads): self
    {
        if ($maxReads > $this->maxReads) {
            // @igor-ignore - Not shared worker service state.
            $this->readsLeft += $maxReads - $this->maxReads;
        }

        // @igor-ignore - Not shared worker service state.
        $this->maxReads = $maxReads;

        return $this;
    }
}
