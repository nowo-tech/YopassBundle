<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;

/**
 * Doctrine ORM storage for share access logs.
 */
final readonly class DoctrineOrmShareAccessLogRepository implements ShareAccessLogRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    /**
     * Creates the repository on the given entity manager.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    /**
     * Returns the most recent access log entries for a share.
     */
    public function findByShare(SecureShare $share, int $limit = 50): array
    {
        /** @var list<ShareAccessLog> $logs */
        $logs = $this->em()->getRepository(ShareAccessLog::class)->findBy(
            ['share' => $share],
            ['accessedAt' => 'DESC'],
            $limit,
        );

        return $logs;
    }

    /**
     * Schedules an access log entry for persistence.
     */
    public function persist(ShareAccessLog $log): void
    {
        $this->em()->persist($log);
    }

    /**
     * Writes pending access log changes to the database.
     */
    public function flush(): void
    {
        $this->em()->flush();
    }
}
