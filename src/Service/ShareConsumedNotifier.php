<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareConsumedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * Dispatches {@see YopassEvents::SHARE_CONSUMED} after a stored read (1.17). The read is already spent, so a throwing
 * listener is logged (share id and exception class only) and ignored.
 *
 * Kept apart from {@see ShareRetriever} so that the only class that logs here never reads the ciphertext
 * (zero-knowledge source scan, audit KN-Y-L1).
 *
 * @internal
 */
final readonly class ShareConsumedNotifier
{
    /**
     * Creates the notifier with an optional event dispatcher (no event without one) and an optional logger.
     */
    public function __construct(
        private ?EventDispatcherInterface $eventDispatcher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Dispatches the event for a share whose read was just stored; never throws.
     */
    public function notify(SecureShare $share, DateTimeImmutable $consumedAt): void
    {
        if (!$this->eventDispatcher instanceof EventDispatcherInterface) {
            return;
        }

        try {
            $this->eventDispatcher->dispatch(ShareConsumedEvent::fromShare($share, $consumedAt), YopassEvents::SHARE_CONSUMED);
        } catch (Throwable $e) {
            $this->logger?->error('Yopass share_consumed listener failed; the read was delivered.', [
                'share_id'  => $share->getId(),
                'exception' => $e::class,
            ]);
        }
    }
}
