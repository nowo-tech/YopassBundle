<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Service;

use DateTimeInterface;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\ValueObject\Uuid;
use Symfony\Component\HttpFoundation\Request;

use function mb_substr;

/**
 * Records successful public share opens (consume) for the creator audit trail.
 */
final readonly class ShareAccessLogger
{
    public function __construct(
        private ShareAccessLogRepositoryInterface $accessLogRepository,
        private bool $enabled,
    ) {
    }

    public function logSuccessfulConsume(SecureShare $share, Request $request): void
    {
        if (!$this->enabled) {
            return;
        }

        $readNumber = $share->getMaxReads() - $share->getReadsLeft();
        $userAgent  = $request->headers->get('User-Agent');

        $log = new ShareAccessLog(
            (string) Uuid::generate(),
            $share,
            $readNumber,
            $request->getClientIp(),
            $userAgent !== null && $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null,
        );

        $this->accessLogRepository->persist($log);
        $this->accessLogRepository->flush();
    }

    /**
     * @return list<array{
     *     accessedAt: string,
     *     readNumber: int,
     *     ipAddress: string|null,
     *     userAgent: string|null
     * }>
     */
    public function listForShare(SecureShare $share, int $limit = 50): array
    {
        if (!$this->enabled) {
            return [];
        }

        $entries = [];

        foreach ($this->accessLogRepository->findByShare($share, $limit) as $log) {
            $entries[] = [
                'accessedAt' => $log->getAccessedAt()->format(DateTimeInterface::ATOM),
                'readNumber' => $log->getReadNumber(),
                'ipAddress'  => $log->getIpAddress(),
                'userAgent'  => $log->getUserAgent(),
            ];
        }

        return $entries;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
