<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
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
        private ?YopassEntityManager $ownManager = null,
        private ?HostFlushProbe $hostFlushProbe = null,
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
     * Returns the most recent access log entries of a share id, also after the share was deleted (1.16).
     *
     * @return list<ShareAccessLog>
     */
    public function findByShareId(string $shareId, int $limit = 50): array
    {
        /** @var list<ShareAccessLog> $logs */
        $logs = $this->em()->getRepository(ShareAccessLog::class)->findBy(
            ['shareRef' => ShareAccessLog::referenceFor($shareId)],
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
        $entityManager = $this->em();
        // 1.22.0 (REQ-ARCH-001 rule 6 deprecation layer): with `database.private_entity_manager: false` this flush
        // writes the application's pending changes too; the probe reports each such class once per unit of work.
        if (!$this->ownManager instanceof YopassEntityManager) {
            $this->hostFlushProbe?->beforeHostFlush($entityManager, YopassEntityManager::BUNDLE_NAME, YopassEntityManager::OWN_NAMESPACES);
        }
        $entityManager->flush();
    }
}
