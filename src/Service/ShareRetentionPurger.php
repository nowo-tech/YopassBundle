<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Support\RelativeInterval;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Deletes shares older than the configured retention age.
 */
final readonly class ShareRetentionPurger
{
    private bool $enabled;

    private string $maxAge;

    private ClockInterface $clock;

    /**
     * Creates the purger with the repository, share options, an optional clock and an optional event dispatcher
     * ({@see YopassEvents::SHARES_BULK_DELETED} with reason "retention" when shares were removed).
     *
     * @param array{
     *     retention?: array{enabled?: bool, max_age?: string}
     * } $shareOptions
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        array $shareOptions,
        ?ClockInterface $clock = null,
        private ?EventDispatcherInterface $eventDispatcher = null,
    ) {
        $retention     = $shareOptions['retention'] ?? [];
        $this->enabled = (bool) ($retention['enabled'] ?? false);
        $this->maxAge  = (string) ($retention['max_age'] ?? '');
        $this->clock   = $clock ?? new Clock();
    }

    /**
     * Whether retention purging is enabled (shares.retention.enabled).
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Deletes the given user's shares older than the retention age; returns the number removed.
     */
    public function purgeForCreator(object $creator): int
    {
        $cutoff = $this->resolveCutoff();

        if (!$cutoff instanceof DateTimeImmutable) {
            return 0;
        }

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $removed = $this->shareRepository->removeByCreatorOlderThan($creator, $cutoff);

        if ($removed > 0) {
            $this->shareRepository->flush();
            $this->dispatchPurged($creator, $removed, $creator instanceof UserInterface ? $creator : null);
        }

        return $removed;
    }

    /**
     * Deletes all shares older than the retention age; returns the number removed.
     */
    public function purgeAll(): int
    {
        $cutoff = $this->resolveCutoff();

        if (!$cutoff instanceof DateTimeImmutable) {
            return 0;
        }

        // @igor-ignore - Service coordinates I/O or request-scoped work; not unsafe worker singleton state.
        $removed = $this->shareRepository->removeOlderThan($cutoff);

        if ($removed > 0) {
            $this->shareRepository->flush();
            $this->dispatchPurged(null, $removed, null);
        }

        return $removed;
    }

    private function dispatchPurged(?object $subject, int $removed, ?UserInterface $viewer): void
    {
        $this->eventDispatcher?->dispatch(
            new ShareBulkDeletedEvent(ShareBulkDeletedEvent::REASON_RETENTION, $subject, $removed, $viewer),
            YopassEvents::SHARES_BULK_DELETED,
        );
    }

    private function resolveCutoff(): ?DateTimeImmutable
    {
        if (!$this->enabled || $this->maxAge === '') {
            return null;
        }

        $cutoff = $this->now()->modify(RelativeInterval::negated($this->maxAge));

        return $cutoff === false ? null : $cutoff;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
