<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeImmutable;
use JsonException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;

use const JSON_THROW_ON_ERROR;

/**
 * Returns encrypted payload and consumes a read — decryption happens in the browser.
 */
final readonly class ShareRetriever
{
    public function __construct(
        private ShareRepositoryInterface $shareRepository,
    ) {
    }

    /**
     * @return array{status: string, ciphertext?: string, mode?: string}
     */
    public function consume(string $shareId): array
    {
        $share = $this->shareRepository->consumeReadIfAvailable($shareId);

        if ($share instanceof SecureShare) {
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

        if ($share->getExpiresAt() <= new DateTimeImmutable()) {
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

    public function availability(SecureShare $share): string
    {
        return $this->resolveAvailability($share);
    }

    private function resolveAvailability(SecureShare $share): string
    {
        if ($share->getRevokedAt() instanceof DateTimeImmutable) {
            return 'revoked';
        }

        if ($share->getExpiresAt() <= new DateTimeImmutable()) {
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
}
