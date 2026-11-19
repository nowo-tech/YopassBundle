<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use Closure;
use DateTimeImmutable;
use JsonException;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

use const JSON_THROW_ON_ERROR;

/**
 * Returns encrypted payload and consumes a read — decryption happens in the browser.
 */
final readonly class ShareRetriever
{
    private ClockInterface $clock;

    /**
     * Creates the retriever with the repository, an optional clock and (1.17) the notifier that dispatches
     * {@see YopassEvents::SHARE_CONSUMED} (no event without one). With the Doctrine ORM driver (1.21.0) the bundle passes
     * its {@see ShareWriteUnit}: the read and the work given to {@see consume()} commit together.
     */
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
        ?ClockInterface $clock = null,
        private ?ShareConsumedNotifier $consumedNotifier = null,
        private ?ShareWriteUnit $writeUnit = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Consumes one read of a share and returns the ciphertext payload or the reason it is unavailable.
     * After a stored read, dispatches {@see YopassEvents::SHARE_CONSUMED}. The read is already spent at that point, so a
     * throwing listener is logged and ignored: the reader still receives the ciphertext.
     *
     * The read is one conditional UPDATE (`reads_left > 0`, not revoked, not expired), so two concurrent reveals never
     * both get the ciphertext of a one-time share: the second waits for the first's row lock and then matches no row.
     * `$inUnit` (1.21.0; the public controller writes the access log row there) runs with the consumed share right after
     * the read, in the same transaction when the bundle's write unit is wired (Doctrine ORM driver): if it fails, or a
     * lock conflict happens, the read is rolled back and nobody received the ciphertext. The event is dispatched and the
     * ciphertext returned only after the commit. Without a write unit (MongoDB, custom repository) the read is stored
     * on its own and `$inUnit` runs after it.
     *
     * @param (Closure(SecureShare): void)|null $inUnit
     *
     * @return array{status: string, ciphertext?: string, mode?: string}
     */
    public function consume(string $shareId, ?Closure $inUnit = null): array
    {
        $consume = function () use ($shareId, $inUnit): ?SecureShare {
            $share = $this->shareRepository->consumeReadIfAvailable($shareId);
            if ($share instanceof SecureShare && $inUnit instanceof Closure) {
                $inUnit($share);
            }

            return $share;
        };
        $share = $this->writeUnit instanceof ShareWriteUnit ? $this->writeUnit->run($consume) : $consume();

        if ($share instanceof SecureShare) {
            $this->consumedNotifier?->notify($share, $this->now());

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

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
