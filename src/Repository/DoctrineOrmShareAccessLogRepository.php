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
     * Creates the repository. The bundle passes its own entity manager (`$ownManager`, the only mode since 2.0, also with
     * the custom driver on a relational platform): the rows are written through it on the connection of
     * `database.entity_manager`, and the application's manager is never flushed. Constructed by hand without it, the
     * repository works on `$entityManager`.
     *
     * @param ManagerRegistry|null $registry ignored since 2.0 (it replaced a closed application manager in the removed
     *                                       host mode); removed in 3.0
     * @param string|null $managerName ignored since 2.0, see `$registry`; removed in 3.0
     * @param HostFlushProbe|null $hostFlushProbe ignored since 2.0 (the 1.22 probe of the removed host mode); removed in 3.0
     */
    // @phpstan-ignore constructor.unusedParameter, constructor.unusedParameter, constructor.unusedParameter (the 1.x host-mode arguments are ignored since 2.0, removed in 3.0)
    public function __construct(
        private EntityManagerInterface $entityManager,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        private ?YopassEntityManager $ownManager = null,
        ?HostFlushProbe $hostFlushProbe = null,
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
     * Schedules an access log entry for persistence. A share the manager does not hold (with the custom driver the share
     * comes from your repository) is related through a reference of the same id, so the flush never meets an unknown
     * entity through the relation.
     */
    public function persist(ShareAccessLog $log): void
    {
        $entityManager = $this->em();
        $share         = $log->getShare();
        if ($share instanceof SecureShare && !$entityManager->contains($share)) {
            $entityManager->getClassMetadata(ShareAccessLog::class)
                ->setFieldValue($log, 'share', $entityManager->getReference(SecureShare::class, $share->getId()));
        }
        $entityManager->persist($log);
    }

    /**
     * Writes pending access log changes to the database.
     */
    public function flush(): void
    {
        $this->em()->flush();
    }
}
