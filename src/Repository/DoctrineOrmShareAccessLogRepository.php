<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;

/**
 * Doctrine ORM storage for share access logs.
 */
final readonly class DoctrineOrmShareAccessLogRepository implements ShareAccessLogRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function findByShare(SecureShare $share, int $limit = 50): array
    {
        /** @var list<ShareAccessLog> $logs */
        $logs = $this->entityManager->getRepository(ShareAccessLog::class)->findBy(
            ['share' => $share],
            ['accessedAt' => 'DESC'],
            $limit,
        );

        return $logs;
    }

    public function persist(ShareAccessLog $log): void
    {
        $this->entityManager->persist($log);
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
