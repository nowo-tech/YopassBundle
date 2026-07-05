<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Doctrine ORM implementation for PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle, etc.
 */
final readonly class DoctrineOrmShareRepository implements ShareRepositoryInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function find(string $id): ?SecureShare
    {
        $share = $this->entityManager->getRepository(SecureShare::class)->find($id);

        return $share instanceof SecureShare ? $share : null;
    }

    public function consumeReadIfAvailable(string $id): ?SecureShare
    {
        $now     = new DateTimeImmutable();
        $updated = (int) $this->entityManager->createQueryBuilder()
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

        $this->entityManager->clear();

        return $this->find($id);
    }

    public function findByCreator(object $creator): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->entityManager->getRepository(SecureShare::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
        );

        return $shares;
    }

    public function countByCreator(object $creator): int
    {
        return $this->entityManager->getRepository(SecureShare::class)->count(['creator' => $creator]);
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        /** @var list<SecureShare> $shares */
        $shares = $this->entityManager->getRepository(SecureShare::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
            $limit,
            $offset,
        );

        return $shares;
    }

    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        return (int) $this->entityManager->createQueryBuilder()
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
        return (int) $this->entityManager->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->setParameter('creator', $creator)
            ->getQuery()
            ->execute();
    }

    public function removeOlderThan(DateTimeImmutable $before): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.createdAt < :before')
            ->setParameter('before', $before)
            ->getQuery()
            ->execute();
    }

    public function persist(SecureShare $share): void
    {
        $this->entityManager->persist($share);
    }

    public function remove(SecureShare $share): void
    {
        $managed = $this->entityManager->find(SecureShare::class, $share->getId());

        if ($managed instanceof SecureShare) {
            $this->entityManager->remove($managed);
        }
    }

    public function flush(): void
    {
        $this->entityManager->flush();
    }
}
