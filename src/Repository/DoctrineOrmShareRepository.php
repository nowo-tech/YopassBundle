<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Entity\SecureShare;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Doctrine ORM implementation for PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle, etc.
 */
final readonly class DoctrineOrmShareRepository implements ShareRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    private ClockInterface $clock;

    public function __construct(
        private EntityManagerInterface $entityManager,
        ?ClockInterface $clock = null,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Always reads the row from the database (refreshing an already managed instance), so a share revoked,
     * extended or consumed by another worker is never served from a stale identity map.
     */
    public function find(string $id): ?SecureShare
    {
        $share = $this->em()->createQueryBuilder()
            ->select('s')
            ->from(SecureShare::class, 's')
            ->where('s.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return $share instanceof SecureShare ? $share : null;
    }

    public function consumeReadIfAvailable(string $id): ?SecureShare
    {
        $now     = $this->now();
        $updated = (int) $this->em()->createQueryBuilder()
            ->update(SecureShare::class, 's')
            ->set('s.readsLeft', 's.readsLeft - 1')
            ->where('s.id = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.expiresAt > :now')
            ->andWhere('s.readsLeft > 0')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();

        if ($updated === 0) {
            return null;
        }

        return $this->find($id);
    }

    public function findByCreator(object $creator): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
        );

        return $shares;
    }

    public function countByCreator(object $creator): int
    {
        return $this->em()->getRepository(SecureShare::class)->count(['creator' => $creator]);
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
            $limit,
            $offset,
        );

        return $shares;
    }

    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->andWhere('s.createdAt < :before')
            ->setParameter('creator', $creator)
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function removeAllByCreator(object $creator): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->setParameter('creator', $creator)
            ->getQuery()
            ->execute();
    }

    public function removeOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function persist(SecureShare $share): void
    {
        $this->em()->persist($share);
    }

    public function remove(SecureShare $share): void
    {
        $managed = $this->em()->find(SecureShare::class, $share->getId());

        if ($managed instanceof SecureShare) {
            $this->em()->remove($managed);
        }
    }

    public function flush(): void
    {
        $this->em()->flush();
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
