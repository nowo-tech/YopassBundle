<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\ValueObject\Uuid;

/**
 * Persists client-encrypted shares (E2E — server stores ciphertext only).
 */
final class ShareCreator
{
    /**
     * @param list<array{id: string, interval: string}> $expirationOptions
     */
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
        private readonly array $expirationOptions,
    ) {
    }

    public function create(object $creator, ShareCreateData $data): SecureShare
    {
        $share = new SecureShare((string) Uuid::generate(), $creator);
        $share
            ->setCiphertext($data->ciphertext)
            ->setExpiresAt($data->resolveExpiresAt($this->expirationOptions))
            ->setMaxReads($data->maxReads)
            ->setPayloadKind($data->payloadKind);

        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        return $share;
    }
}
