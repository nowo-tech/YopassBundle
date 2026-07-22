<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Document;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\Mapping\Annotations as ODM;
use Nowo\YopassBundle\Entity\SecureShare;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * MongoDB document mapped to the same logical schema as {@see SecureShare}.
 */
#[ODM\Document]
class SecureShareDocument
{
    #[ODM\Field(type: 'string')]
    private string $ciphertext = '';

    #[ODM\Field(type: 'date_immutable')]
    private DateTimeImmutable $expiresAt;

    #[ODM\Field(type: 'int')]
    private int $maxReads = 1;

    #[ODM\Field(type: 'int')]
    private int $readsLeft = 1;

    #[ODM\Field(type: 'date_immutable')]
    private DateTimeImmutable $createdAt;

    #[ODM\Field(type: 'date_immutable', nullable: true)]
    private ?DateTimeImmutable $revokedAt = null;

    #[ODM\Field(type: 'string')]
    private string $payloadKind = 'text';

    public function __construct(#[ODM\Id(type: 'string', strategy: 'NONE')]
        private string $id, /** @var object Application user entity (nowo_yopass.user_class) */
        #[ODM\ReferenceOne(storeAs: 'id', targetDocument: UserInterface::class)]
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
