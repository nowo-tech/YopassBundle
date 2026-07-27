<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Deletes shares older than the configured retention age.
 */
final readonly class ShareRetentionPurger
{
    private bool $enabled;

    private string $maxAge;

    private ClockInterface $clock;

    /**
     * @param array{
     *     retention?: array{enabled?: bool, max_age?: string}
     * } $shareOptions
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        array $shareOptions,
        ?ClockInterface $clock = null,
    ) {
        $retention     = $shareOptions['retention'] ?? [];
        $this->enabled = (bool) ($retention['enabled'] ?? false);
        $this->maxAge  = (string) ($retention['max_age'] ?? '');
        $this->clock   = $clock ?? new Clock();
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function purgeForCreator(object $creator): int
    {
        $cutoff = $this->resolveCutoff();

        if (!$cutoff instanceof DateTimeImmutable) {
            return 0;
        }

        $removed = $this->shareRepository->removeByCreatorOlderThan($creator, $cutoff);

        if ($removed > 0) {
            $this->shareRepository->flush();
        }

        return $removed;
    }

    public function purgeAll(): int
    {
        $cutoff = $this->resolveCutoff();

        if (!$cutoff instanceof DateTimeImmutable) {
            return 0;
        }

        $removed = $this->shareRepository->removeOlderThan($cutoff);

        if ($removed > 0) {
            $this->shareRepository->flush();
        }

        return $removed;
    }

    private function resolveCutoff(): ?DateTimeImmutable
    {
        if (!$this->enabled || $this->maxAge === '') {
            return null;
        }

        $cutoff = $this->now()->modify('-' . $this->maxAge);

        return $cutoff === false ? null : $cutoff;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
