<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

/**
 * Deletes shares older than the configured retention age.
 */
final class ShareRetentionPurger
{
    private readonly bool $enabled;

    private readonly string $maxAge;

    /**
     * @param array{
     *     retention?: array{enabled?: bool, max_age?: string}
     * } $shareOptions
     */
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
        array $shareOptions,
    ) {
        $retention     = $shareOptions['retention'] ?? [];
        $this->enabled = (bool) ($retention['enabled'] ?? false);
        $this->maxAge  = (string) ($retention['max_age'] ?? '');
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function purgeForCreator(object $creator): int
    {
        $cutoff = $this->resolveCutoff();

        if ($cutoff === null) {
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

        if ($cutoff === null) {
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

        $cutoff = (new DateTimeImmutable())->modify('-' . $this->maxAge);

        return $cutoff === false ? null : $cutoff;
    }
}
