<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassSavepoint;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Share\ShareView;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

use function array_chunk;
use function array_column;
use function array_filter;
use function array_slice;
use function array_values;
use function count;
use function in_array;
use function is_string;
use function max;
use function preg_match;

/**
 * Doctrine ORM implementation for PostgreSQL, MySQL, MariaDB, SQLite, SQL Server, Oracle, etc.
 */
final readonly class DoctrineOrmShareRepository implements ShareRepositoryInterface, MaxReadsRaisingShareRepositoryInterface, ExpirationExtendingShareRepositoryInterface, CreatorIdShareRepositoryInterface, ShareSummaryRepositoryInterface, RevokingShareRepositoryInterface
{
    use ResolvesEntityManagerTrait;

    /** Shares deleted per statement by the retention purge (1.21.0). */
    private const PURGE_CHUNK = 500;

    private ClockInterface $clock;

    /**
     * Creates the repository and its clock; the creator resolver turns a non-entity user into a reference before the
     * creator queries. The bundle passes its own entity manager (`$ownManager`, the only mode since 2.0): every read and
     * write goes through it and the application's manager is never flushed. Constructed by hand without it, the
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
        ?ClockInterface $clock = null,
        ?ManagerRegistry $registry = null,
        ?string $managerName = null,
        private ?CreatorReferenceResolverInterface $creators = null,
        private ?YopassEntityManager $ownManager = null,
        ?HostFlushProbe $hostFlushProbe = null,
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
        $updated = $this->execute($this->em()->createQueryBuilder()
            ->update(SecureShare::class, 's')
            ->set('s.readsLeft', 's.readsLeft - 1')
            ->where('s.id = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.expiresAt > :now')
            ->andWhere('s.readsLeft > 0')
            ->setParameter('id', $id)
            ->setParameter('now', $now)
            ->getQuery());

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

        $updated = $this->execute($this->em()->createQueryBuilder()
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
            ->getQuery());

        if ($this->em()->contains($share)) {
            $this->em()->refresh($share);
        }

        return $updated > 0;
    }

    /**
     * One conditional UPDATE (`expires_at = :new` where not revoked and the stored expiration is earlier, 1.21.0); a
     * managed instance is refreshed afterwards.
     */
    public function extendExpiration(SecureShare $share, DateTimeImmutable $expiresAt): bool
    {
        $updated = $this->execute($this->em()->createQueryBuilder()
            ->update(SecureShare::class, 's')
            ->set('s.expiresAt', ':expiresAt')
            ->where('s.id = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->andWhere('s.expiresAt < :expiresAt')
            ->setParameter('expiresAt', $expiresAt)
            ->setParameter('id', $share->getId())
            ->getQuery());

        if ($this->em()->contains($share)) {
            $this->em()->refresh($share);
        }

        return $updated > 0;
    }

    /**
     * One conditional UPDATE (`revoked_at = now, reads_left = 0` where the id matches and `revoked_at IS NULL`, 2.1.1):
     * the affected-row count decides, so of two concurrent revokes only one returns true, and a share deleted meanwhile
     * returns false. A managed instance is refreshed after a successful write (a deleted row is never refreshed).
     */
    public function revokeIfActive(SecureShare $share): bool
    {
        $updated = $this->execute($this->em()->createQueryBuilder()
            ->update(SecureShare::class, 's')
            ->set('s.revokedAt', ':now')
            ->set('s.readsLeft', '0')
            ->where('s.id = :id')
            ->andWhere('s.revokedAt IS NULL')
            ->setParameter('now', $this->now())
            ->setParameter('id', $share->getId())
            ->getQuery());

        if ($updated > 0 && $this->em()->contains($share)) {
            $this->em()->refresh($share);
        }

        return $updated > 0;
    }

    /**
     * Returns all shares created by the given user.
     */
    public function findByCreator(object $creator): array
    {
        $value = $this->creator($creator);
        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $value],
            ['createdAt' => 'DESC'],
        );

        return $this->exact($shares, $value);
    }

    /**
     * Counts the shares created by the given user.
     */
    public function countByCreator(object $creator): int
    {
        $value = $this->creator($creator);
        if (!$this->mapsCreatorById() || !is_string($value)) {
            return $this->em()->getRepository(SecureShare::class)->count(['creator' => $value]);
        }

        return count($this->exactIds($value));
    }

    /**
     * Returns one page of shares created by the given user.
     */
    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        $value = $this->creator($creator);
        if ($this->mapsCreatorById() && is_string($value)) {
            return $this->exactPage($value, $limit, $offset);
        }

        /** @var list<SecureShare> $shares */
        $shares = $this->em()->getRepository(SecureShare::class)->findBy(
            ['creator' => $value],
            ['createdAt' => 'DESC'],
            $limit,
            $offset,
        );

        return $this->exact($shares, $value);
    }

    /**
     * Deletes the shares of the given user created before a date; returns the number removed.
     */
    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        $value = $this->creator($creator);
        if ($this->mapsCreatorById() && is_string($value)) {
            return $this->removeIds($this->exactIds($value, $before));
        }

        return $this->execute($this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->andWhere('s.createdAt < :before')
            ->setParameter('creator', $value)
            ->setParameter('before', $before)
            ->getQuery());
    }

    /**
     * Deletes every share of the given user; returns the number removed.
     */
    public function removeAllByCreator(object $creator): int
    {
        $value = $this->creator($creator);
        if ($this->mapsCreatorById() && is_string($value)) {
            return $this->removeIds($this->exactIds($value));
        }

        return $this->execute($this->em()->createQueryBuilder()
            ->delete(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->setParameter('creator', $value)
            ->getQuery());
    }

    /**
     * Deletes all shares created before a date; returns the number removed.
     */
    public function removeOlderThan(DateTimeImmutable $before): int
    {
        // 1.21.0 (REQ-RUNTIME-003 rule 9): small chunks by primary key, each its own statement (its own transaction
        // outside one), instead of one DELETE over the created_at range, which on a large table holds range locks for
        // a long time and blocks new shares and reveals.
        $removed = 0;
        do {
            /** @var list<array{id: string}> $rows */
            $rows = $this->em()->createQueryBuilder()
                ->select('s.id AS id')
                ->from(SecureShare::class, 's')
                ->where('s.createdAt < :before')
                ->setParameter('before', $before)
                ->orderBy('s.createdAt', 'ASC')
                ->addOrderBy('s.id', 'ASC')
                ->setMaxResults(self::PURGE_CHUNK)
                ->getQuery()
                ->getArrayResult();
            if ($rows === []) {
                break;
            }

            // The cutoff is checked again: a share is never deleted on the strength of the read alone.
            $deleted = $this->execute($this->em()->createQueryBuilder()
                ->delete(SecureShare::class, 's')
                ->where('s.id IN (:ids)')
                ->andWhere('s.createdAt < :before')
                ->setParameter('ids', array_column($rows, 'id'))
                ->setParameter('before', $before)
                ->getQuery());
            $removed += $deleted;
            // A chunk that deleted nothing (another purge took it) ends the run: the next purge sees the rest.
        } while ($deleted > 0 && count($rows) === self::PURGE_CHUNK);

        return $removed;
    }

    /**
     * Schedules a share for persistence. A new share whose creator entity the bundle's manager does not hold (a user the
     * application loaded through its own manager) is related through a reference of the same class and id in the
     * bundle's manager (2.0.1, {@see ownCreator()}), so the flush never meets that user as a new entity.
     */
    public function persist(SecureShare $share): void
    {
        $entityManager = $this->em();
        if (!$entityManager->contains($share)) {
            $creator = $share->getCreator();
            $own     = $this->ownCreator($creator);
            if ($own !== $creator) {
                $entityManager->getClassMetadata(SecureShare::class)->setFieldValue($share, 'creator', $own);
            }
        }
        $entityManager->persist($share);
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
     * The shares of these creators as views (2.1.0): scalar columns only (no ciphertext), chunked `IN` lists, then
     * the exact creator id re-checked in PHP.
     */
    public function viewsByCreatorIds(array $creatorIds, DateTimeImmutable $now): array
    {
        $byId   = $this->mapsCreatorById();
        $values = $byId ? $creatorIds : $this->relationCreatorValues($creatorIds);
        $wanted = [];
        foreach ($creatorIds as $creatorId) {
            $wanted['k' . $creatorId] = true;
        }

        $views = [];
        foreach (array_chunk($values, self::PURGE_CHUNK) as $chunk) {
            foreach ($this->summaryRows($byId ? 's.creator IN (:values)' : 'IDENTITY(s.creator) IN (:values)', $chunk) as $row) {
                if ($row['creator'] !== null && isset($wanted['k' . $row['creator']])) {
                    $views[] = $this->view($row, $now);
                }
            }
        }

        return $views;
    }

    /**
     * The shares with these ids as views (2.1.0): scalar columns only (no ciphertext), the exact id re-checked in PHP.
     */
    public function viewsByIds(array $shareIds, DateTimeImmutable $now): array
    {
        $wanted = [];
        foreach ($shareIds as $shareId) {
            $wanted['k' . $shareId] = true;
        }

        $views = [];
        foreach (array_chunk($shareIds, self::PURGE_CHUNK) as $chunk) {
            foreach ($this->summaryRows('s.id IN (:values)', $chunk) as $row) {
                if (isset($wanted['k' . $row['id']])) {
                    $views[] = $this->view($row, $now);
                }
            }
        }

        return $views;
    }

    /**
     * Deletes the shares with these ids (2.1.0): DQL DELETE per chunk inside a savepoint when the application opened a
     * transaction; the affected-row count is what this call removed.
     */
    public function removeByIds(array $shareIds): int
    {
        return $shareIds === [] ? 0 : $this->removeIds($shareIds);
    }

    /**
     * Scalar rows of the shares matching one condition.
     *
     * @param non-empty-list<int|string> $values
     *
     * @return list<array{id: string, creator: string|null, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable, maxReads: int, readsLeft: int, payloadKind: string, revokedAt: DateTimeImmutable|null}>
     */
    private function summaryRows(string $condition, array $values): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->em()->createQueryBuilder()
            ->select(
                's.id AS id',
                ($this->mapsCreatorById() ? 's.creator' : 'IDENTITY(s.creator)') . ' AS creator',
                's.createdAt AS createdAt',
                's.expiresAt AS expiresAt',
                's.maxReads AS maxReads',
                's.readsLeft AS readsLeft',
                's.payloadKind AS payloadKind',
                's.revokedAt AS revokedAt',
            )
            ->from(SecureShare::class, 's')
            ->where($condition)
            ->setParameter('values', $values)
            ->getQuery()
            ->getArrayResult();

        $typed = [];
        foreach ($rows as $row) {
            $typed[] = [
                'id'          => (string) $row['id'],
                'creator'     => $row['creator'] === null ? null : (string) $row['creator'],
                'createdAt'   => $this->date($row['createdAt']) ?? new DateTimeImmutable('@0'),
                'expiresAt'   => $this->date($row['expiresAt']) ?? new DateTimeImmutable('@0'),
                'maxReads'    => (int) $row['maxReads'],
                'readsLeft'   => (int) $row['readsLeft'],
                'payloadKind' => (string) $row['payloadKind'],
                'revokedAt'   => $this->date($row['revokedAt']),
            ];
        }

        return $typed;
    }

    /**
     * @param array{id: string, creator: string|null, createdAt: DateTimeImmutable, expiresAt: DateTimeImmutable, maxReads: int, readsLeft: int, payloadKind: string, revokedAt: DateTimeImmutable|null} $row
     */
    private function view(array $row, DateTimeImmutable $now): ShareView
    {
        return new ShareView($row['id'], $row['creator'], $row['createdAt'], $row['expiresAt'], $row['maxReads'], $row['readsLeft'], $row['payloadKind'], $row['revokedAt'], $now);
    }

    /**
     * A selected date column as a DateTimeImmutable (the ORM converts mapped fields; a raw string is parsed).
     */
    private function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }

    /**
     * With the relation mapping, the creator ids as the user identifier type: integers for an integer key (an id that
     * is not a plain decimal number cannot match one and is dropped), strings otherwise. The exact string comparison
     * afterwards drops `05` for user 5.
     *
     * @param list<string> $creatorIds
     *
     * @return list<int|string>
     */
    private function relationCreatorValues(array $creatorIds): array
    {
        $entityManager = $this->em();
        $target        = $entityManager->getClassMetadata($entityManager->getClassMetadata(SecureShare::class)->getAssociationTargetClass('creator'));
        $identifiers   = $target->getIdentifierFieldNames();
        $type          = count($identifiers) === 1 ? $target->getTypeOfField($identifiers[0]) : null;
        if (!in_array($type, ['integer', 'smallint', 'bigint'], true)) {
            return $creatorIds;
        }

        $values = [];
        foreach ($creatorIds as $creatorId) {
            if (preg_match('/^(?:0|[1-9][0-9]{0,17})$/D', $creatorId) === 1) {
                $values[] = (int) $creatorId;
            }
        }

        return $values;
    }

    /**
     * Whether shares store the creator id in a plain column (`user_mapping: id`, 1.18.0).
     */
    private function mapsCreatorById(): bool
    {
        return $this->em()->getClassMetadata(SecureShare::class)->hasField('creator');
    }

    /**
     * Id mapping (LQ-Y-L1): keeps the shares whose stored creator id equals the requested one byte for byte, whatever
     * the column collation matched (a case-insensitive collation would also return `AbC` for `abc`).
     *
     * @param list<SecureShare> $shares
     *
     * @return list<SecureShare>
     */
    private function exact(array $shares, object|string $value): array
    {
        if (!is_string($value)) {
            return $shares;
        }

        return array_values(array_filter($shares, static fn (SecureShare $share): bool => $share->getCreatorId() === $value));
    }

    /**
     * Id mapping (LQ-Y-L1): ids of the shares whose stored creator id is exactly `$creatorId` (optionally created
     * before a date).
     *
     * @return list<string>
     */
    private function exactIds(string $creatorId, ?DateTimeImmutable $before = null, bool $newestFirst = false): array
    {
        $query = $this->em()->createQueryBuilder()
            ->select('s.id AS id', 's.creator AS creator')
            ->from(SecureShare::class, 's')
            ->where('s.creator = :creator')
            ->setParameter('creator', $creatorId);
        if ($before instanceof DateTimeImmutable) {
            $query->andWhere('s.createdAt < :before')->setParameter('before', $before);
        }
        if ($newestFirst) {
            $query->orderBy('s.createdAt', 'DESC')->addOrderBy('s.id', 'DESC');
        }

        $ids = [];
        /** @var list<array{id: string, creator: string}> $rows */
        $rows = $query->getQuery()->getArrayResult();
        foreach ($rows as $row) {
            if ($row['creator'] === $creatorId) {
                $ids[] = $row['id'];
            }
        }

        return $ids;
    }

    /**
     * Id mapping (REQ-SEC-006 item 6, since 1.20.4): one page of the shares whose creator id is exactly `$creatorId`,
     * newest first. The exact match happens before the page is cut: the ids of the matching rows are read and
     * re-checked with `===` first, then the page is sliced and loaded by primary key, so rows of a look-alike creator
     * (`AbC`, `abc `) that a folding collation returns never take the place of the creator's own shares.
     *
     * @return list<SecureShare>
     */
    private function exactPage(string $creatorId, int $limit, int $offset): array
    {
        $ids = array_slice($this->exactIds($creatorId, null, true), max(0, $offset), max(0, $limit));
        if ($ids === []) {
            return [];
        }

        /** @var list<SecureShare> $shares */
        $shares = $this->em()->createQueryBuilder()
            ->select('s')
            ->from(SecureShare::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
        $byId = [];
        foreach ($shares as $share) {
            $byId[$share->getId()] = $share;
        }
        $page = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $page[] = $byId[$id];
            }
        }

        return $this->exact($page, $creatorId);
    }

    /**
     * Runs a bulk DQL write inside a savepoint when the application opened a transaction (1.20.0, {@see YopassSavepoint}),
     * so a failing statement never aborts the application's transaction.
     */
    private function execute(Query $query): int
    {
        return (int) YopassSavepoint::write($this->em()->getConnection(), static fn (): mixed => $query->execute());
    }

    /**
     * Deletes the given shares (DQL DELETE per chunk, no flush).
     *
     * @param list<string> $ids
     */
    private function removeIds(array $ids): int
    {
        $removed = 0;
        foreach (array_chunk($ids, 500) as $chunk) {
            $removed += $this->execute($this->em()->createQueryBuilder()
                ->delete(SecureShare::class, 's')
                ->where('s.id IN (:ids)')
                ->setParameter('ids', $chunk)
                ->getQuery());
        }

        return $removed;
    }

    /**
     * The value compared with the stored creator: the reference (relation mapping) or the plain id (id mapping).
     */
    private function creator(object $creator): object|string
    {
        $value = CreatorId::unwrap($this->creators?->reference($creator) ?? $creator);

        return is_string($value) ? $value : $this->ownCreator($value);
    }

    /**
     * The creator as the bundle's own entity manager holds it (2.0.1). With the relation mapping, a `user_class` entity
     * that manager does not hold (one the application's manager loaded, or a detached one) becomes
     * `getReference(user_class, id)` on the bundle's manager: no query, never merged or detached, and the application's
     * manager and its instance are left untouched. The id comes from the application's unit of work when it manages the
     * user (an uninitialised proxy is not loaded), otherwise from the mapped identifier fields. Any other value (no
     * private manager, the id mapping, an object of another class, a user without an id yet) is returned unchanged.
     *
     * 2.0 made the private manager the only mode, also for the bundle's repository decorated by a `database.driver:
     * custom` repository, whose creator resolver passes the user as given, and for a host alias of the creator resolver:
     * the share then pointed at the application's instance and the flush failed with "A new entity was found through the
     * relationship SecureShare#creator".
     */
    private function ownCreator(object $creator): object
    {
        if (!$this->ownManager instanceof YopassEntityManager) {
            return $creator;
        }

        $entityManager = $this->ownManager->get();
        $metadata      = $entityManager->getClassMetadata(SecureShare::class);
        if (!$metadata->hasAssociation('creator') || $entityManager->contains($creator)) {
            return $creator;
        }

        $class = $metadata->getAssociationTargetClass('creator');
        if (!$creator instanceof $class) {
            return $creator;
        }

        $source = $this->ownManager->source();
        $id     = $source->contains($creator)
            ? $source->getUnitOfWork()->getEntityIdentifier($creator)
            : $entityManager->getClassMetadata($class)->getIdentifierValues($creator);
        if ($id === []) {
            return $creator;
        }

        return $entityManager->getReference($class, $id) ?? $creator;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
