<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Entity\SecureShare;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Doctrine ORM implementation for PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle, etc.
 */
final readonly class DoctrineOrmShareRepository implements ShareRepositoryInterface, MaxReadsRaisingShareRepositoryInterface, CreatorIdShareRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    private ClockInterface $clock;

    /**
     * Creates the repository on the given Doctrine manager (optional registry/manager name for resets) and clock; the
     * creator resolver turns a non-entity user into a reference before the creator queries.
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        ?ClockInterface $clock = null,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?CreatorReferenceResolverInterface $creators = null,
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

    /**
     * Atomically consumes one read of an available share; returns null when it is not available.
     */
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

    /**
     * One conditional UPDATE (`reads_left = reads_left + delta` where not revoked and max reads unchanged); a managed
     * instance is refreshed afterwards.
     */
    public function raiseMaxReads(SecureShare $share, int $maxReads): bool
    {
        $oldMaxReads = $share->getMaxReads();
        if ($maxReads <= $oldMaxReads) {
            return false;
        }

        $updated = (int) $this->em()->createQueryBuilder()
            ->update(SecureShare::class, 's')
            ->set('s.readsLeft', 's.readsLeft + :delta')
            ->set('s.maxReads', ':maxReads')
            ->where('s.id = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.maxReads = :oldMaxReads')
            ->setParameter('delta', $maxReads - $oldMaxReads)
            ->setParameter('maxReads', $maxReads)
            ->setParameter('id', $share->getId())
            ->setParameter('oldMaxReads', $oldMaxReads)
            ->getQuery()
            ->execute();

        if ($this->em()->contains($share)) {
            $this->em()->refresh($share);
        }

        return $updated > 0;
    }

    /**
     * Returns all shares created by the given user.
     */
    public function findByCreator(object $creator): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $this->creator($creator)],
            ['createdAt' => 'DESC'],
        );

        return $shares;
    }

    /**
     * Counts the shares created by the given user.
     */
    public function countByCreator(object $creator): int
    {
        return $this->em()->getRepository(SecureShare::class)->count(['creator' => $this->creator($creator)]);
    }

    /**
     * Returns one page of shares created by the given user.
     */
    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $this->creator($creator)],
            ['createdAt' => 'DESC'],
            $limit,
            $offset,
        );

        return $shares;
    }

    /**
     * Deletes the shares of the given user created before a date; returns the number removed.
     */
    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->andWhere('s.createdAt < :before')
            ->setParameter('creator', $this->creator($creator))
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes every share of the given user; returns the number removed.
     */
    public function removeAllByCreator(object $creator): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->setParameter('creator', $this->creator($creator))
            ->getQuery()
            ->execute();
    }

    /**
     * Deletes all shares created before a date; returns the number removed.
     */
    public function removeOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    /**
     * Schedules a share for persistence.
     */
    public function persist(SecureShare $share): void
    {
        $this->em()->persist($share);
    }

    /**
     * Schedules a share for removal.
     */
    public function remove(SecureShare $share): void
    {
        $managed = $this->em()->find(SecureShare::class, $share->getId());

        if ($managed instanceof SecureShare) {
            $this->em()->remove($managed);
        }
    }

    /**
     * Writes pending share changes to storage.
     */
    public function flush(): void
    {
        $this->em()->flush();
    }

    /**
     * Returns all shares created by the user with this id (1.18.0).
     */
    public function findByCreatorId(string $creatorId): array
    {
        return $this->findByCreator(new CreatorId($creatorId));
    }

    /**
     * Counts the shares created by the user with this id (1.18.0).
     */
    public function countByCreatorId(string $creatorId): int
    {
        return $this->countByCreator(new CreatorId($creatorId));
    }

    /**
     * Deletes every share of the user with this id at once (one DQL DELETE, no flush) (1.18.0).
     */
    public function removeAllByCreatorId(string $creatorId): int
    {
        return $this->removeAllByCreator(new CreatorId($creatorId));
    }

    /**
     * The value compared with the stored creator: the reference (relation mapping) or the plain id (id mapping).
     */
    private function creator(object $creator): object|string
    {
        return CreatorId::unwrap($this->creators?->reference($creator) ?? $creator);
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
