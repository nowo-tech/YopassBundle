<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\ValueObject\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Persists client-encrypted shares (E2E — server stores ciphertext only).
 */
final readonly class ShareCreator
{
    private ClockInterface $clock;

    /**
     * Creates the share creator.
     *
     * @param ShareRepositoryInterface $shareRepository Share persistence
     * @param list<array{id: string, interval: string}> $expirationOptions Allowed expiration options
     * @param ClockInterface|null $clock Clock used to compute expiration dates (REQ-DI-001)
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private array $expirationOptions,
        ?ClockInterface $clock = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Creates and persists a share holding the client-encrypted ciphertext.
     *
     * @param object $creator Authenticated user creating the share
     * @param ShareCreateData $data Submitted share metadata and ciphertext
     *
     * @return SecureShare The persisted share
     */
    public function create(object $creator, ShareCreateData $data): SecureShare
    {
        $share = new SecureShare((string) Uuid::generate(), $creator);
        $share
            ->setCiphertext($data->ciphertext)
            ->setExpiresAt($data->resolveExpiresAt($this->expirationOptions, $this->clock->now()))
            ->setMaxReads($data->maxReads)
            ->setPayloadKind($data->payloadKind);

        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        return $share;
    }
}
