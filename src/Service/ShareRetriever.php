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
final class ShareRetriever
{
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
    ) {
    }

    /**
     * @return array{status: string, ciphertext?: string, mode?: string}
     */
    public function consume(string $shareId): array
    {
        $share = $this->shareRepository->find($shareId);

        if (!$share instanceof SecureShare) {
            return ['status' => 'not_found'];
        }

        if ($share->getRevokedAt() !== null) {
            return ['status' => 'revoked'];
        }

        if ($share->getExpiresAt() <= new DateTimeImmutable()) {
            return ['status' => 'expired'];
        }

        if ($share->getReadsLeft() <= 0) {
            return ['status' => 'consumed'];
        }

        $ciphertext = $share->getCiphertext();
        $mode       = $this->resolveMode($ciphertext);

        $share->consumeRead();
        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        return [
            'status'     => 'ok',
            'ciphertext' => $ciphertext,
            'mode'       => $mode,
        ];
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
            'extendable'   => $share->getRevokedAt() === null,
        ];
    }

    public function availability(SecureShare $share): string
    {
        return $this->resolveAvailability($share);
    }

    private function resolveAvailability(SecureShare $share): string
    {
        if ($share->getRevokedAt() !== null) {
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
                return (string) $parsed['mode'];
            }
        } catch (JsonException) {
            // Legacy raw box format.
        }

        return 'key';
    }
}
