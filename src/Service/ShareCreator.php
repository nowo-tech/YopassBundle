<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\ValueObject\Uuid;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

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
     * @param EventDispatcherInterface|null $eventDispatcher Dispatches {@see YopassEvents::SHARE_CREATED} (metadata only)
     * @param CreatorReferenceResolverInterface|null $creators Turns a non-entity user into the stored creator reference
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        private array $expirationOptions,
        ?ClockInterface $clock = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?CreatorReferenceResolverInterface $creators = null,
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
        $share = new SecureShare((string) Uuid::generate(), $this->creators?->reference($creator) ?? $creator);
        $share
            ->setCiphertext($data->ciphertext)
            ->setExpiresAt($data->resolveExpiresAt($this->expirationOptions, $this->clock->now()))
            ->setMaxReads($data->maxReads)
            ->setPayloadKind($data->payloadKind);

        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        // Zero-knowledge: the event carries metadata only (never the ciphertext).
        $this->eventDispatcher?->dispatch(ShareCreatedEvent::fromShare($share), YopassEvents::SHARE_CREATED);

        return $share;
    }
}
