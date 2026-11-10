<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use JsonException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareConsumedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

use const JSON_THROW_ON_ERROR;

/**
 * Returns encrypted payload and consumes a read — decryption happens in the browser.
 */
final readonly class ShareRetriever
{
    private ClockInterface $clock;

    /**
     * Creates the retriever with the repository, an optional clock and an optional event dispatcher
     * ({@see YopassEvents::SHARE_CONSUMED}; no event without one).
     *
     * @param LoggerInterface|null $logger Logs a failing SHARE_CONSUMED listener (share id and exception class only)
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        ?ClockInterface $clock = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Consumes one read of a share and returns the ciphertext payload or the reason it is unavailable.
     * After a stored read, dispatches {@see YopassEvents::SHARE_CONSUMED}. The read is already spent at that point, so a
     * throwing listener is logged and ignored: the reader still receives the ciphertext.
     *
     * @return array{status: string, ciphertext?: string, mode?: string}
     */
    public function consume(string $shareId): array
    {
        $share = $this->shareRepository->consumeReadIfAvailable($shareId);

        if ($share instanceof SecureShare) {
            $this->dispatchConsumed($share);

            $ciphertext = $share->getCiphertext();

            return [
                'status'     => 'ok',
                'ciphertext' => $ciphertext,
                'mode'       => $this->resolveMode($ciphertext),
            ];
        }

        $share = $this->shareRepository->find($shareId);

        if (!$share instanceof SecureShare) {
            return ['status' => 'not_found'];
        }

        if ($share->getRevokedAt() instanceof DateTimeImmutable) {
            return ['status' => 'revoked'];
        }

        if ($share->getExpiresAt() <= $this->now()) {
            return ['status' => 'expired'];
        }

        return ['status' => 'consumed'];
    }

    /**
     * Returns ciphertext for the share creator without consuming a read.
     *
     * @return array{
     *     status: string,
     *     availability?: string,
     *     ciphertext?: string,
     *     mode?: string,
     *     payloadKind?: string
     * }
     */
    public function preview(string $shareId): array
    {
        $share = $this->shareRepository->find($shareId);

        if (!$share instanceof SecureShare) {
            return ['status' => 'not_found'];
        }

        $ciphertext = $share->getCiphertext();

        return [
            'status'       => 'ok',
            'availability' => $this->resolveAvailability($share),
            'ciphertext'   => $ciphertext,
            'mode'         => $this->resolveMode($ciphertext),
            'payloadKind'  => $share->getPayloadKind(),
            'maxReads'     => $share->getMaxReads(),
            'readsLeft'    => $share->getReadsLeft(),
            'expiresAt'    => $share->getExpiresAt()->format(DateTimeImmutable::ATOM),
            'extendable'   => !$share->getRevokedAt() instanceof DateTimeImmutable,
        ];
    }

    /**
     * Returns the availability state of a share ("active", "revoked", "expired" or "consumed").
     */
    public function availability(SecureShare $share): string
    {
        return $this->resolveAvailability($share);
    }

    private function resolveAvailability(SecureShare $share): string
    {
        if ($share->getRevokedAt() instanceof DateTimeImmutable) {
            return 'revoked';
        }

        if ($share->getExpiresAt() <= $this->now()) {
            return 'expired';
        }

        if ($share->getReadsLeft() <= 0) {
            return 'consumed';
        }

        return 'active';
    }

    private function resolveMode(string $ciphertext): string
    {
        try {
            /** @var array{v?: int, mode?: string} $parsed */
            $parsed = json_decode($ciphertext, true, 512, JSON_THROW_ON_ERROR);

            if (($parsed['v'] ?? null) === 1 && isset($parsed['mode'])) {
                return $parsed['mode'];
            }
        } catch (JsonException) {
            // Legacy raw box format.
        }

        return 'key';
    }

    private function dispatchConsumed(SecureShare $share): void
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch(ShareConsumedEvent::fromShare($share, $this->now()), YopassEvents::SHARE_CONSUMED);
        } catch (Throwable $e) {
            $this->logger?->error('Yopass share_consumed listener failed; the read was delivered.', [
                'share_id'  => $share->getId(),
                'exception' => $e::class,
            ]);
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
