<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Support\RelativeInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

use const DATE_ATOM;

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
     * ({@see YopassEvents::SHARES_BULK_DELETED} with reason "retention" when shares were removed) and an optional
     * logger (a skipped purge).
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
        private ?LoggerInterface $logger = null,
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
     * Whether retention is on but `max_age` gives no usable cutoff, so a purge deletes nothing and checks nothing.
     */
    public function isCutoffRefused(): bool
    {
        return $this->enabled && !RelativeInterval::retentionCutoff($this->now(), $this->maxAge) instanceof DateTimeImmutable;
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

        $cutoff = RelativeInterval::retentionCutoff($this->now(), $this->maxAge);

        // The config validator refuses these; a value can still get here another way, or depend on the date
        // (`1 month -30 days`). Deleting with a cutoff not in the past would remove every live share.
        if (!$cutoff instanceof DateTimeImmutable) {
            $this->logger?->error('nowo_yopass: retention purge skipped, shares.retention.max_age "{max_age}" is malformed or its cutoff is out of range.', ['max_age' => $this->maxAge]);

            return null;
        }

        if (!RelativeInterval::isPlainTerms($this->maxAge)) {
            $this->logger?->warning('nowo_yopass: shares.retention.max_age "{max_age}" is not plain <N> <unit> terms, so it can delete far more than it reads (cutoff {cutoff}). This will be refused in a future major version.', ['max_age' => $this->maxAge, 'cutoff' => $cutoff->format(DATE_ATOM)]);
        }

        return $cutoff;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
