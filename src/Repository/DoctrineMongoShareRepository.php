<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;
use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

use function is_int;

/**
 * Doctrine MongoDB ODM implementation for MongoDB deployments.
 */
final readonly class DoctrineMongoShareRepository implements ShareRepositoryInterface
{
    use ResolvesDocumentManagerTrait;

    private ClockInterface $clock;

    /**
     * Creates the repository on the given Doctrine manager (optional registry/manager name for resets) and clock; the
     * creator resolver turns a non-entity user into a reference before the creator queries.
     */
    public function __construct(
        private DocumentManager $documentManager,
        ?ClockInterface $clock = null,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?CreatorReferenceResolverInterface $creators = null,
    ) {
        $this->clock = $clock ?? new Clock();
    }

    /**
     * Refreshes an already managed document, so a share changed by another worker is never mapped from a stale identity map.
     */
    public function find(string $id): ?SecureShare
    {
        $dm       = $this->dm();
        $document = $dm->find(SecureShareDocument::class, $id);
        if (!$document instanceof SecureShareDocument) {
            return null;
        }

        $dm->refresh($document);

        return $this->toEntity($document);
    }

    /**
     * Atomically consumes one read of an available share; returns null when it is not available.
     */
    public function consumeReadIfAvailable(string $id): ?SecureShare
    {
        $now = $this->now();

        /** @var SecureShareDocument|null $document */
        $document = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->findAndUpdate()
            ->returnNew(true)
            ->field('_id')->equals($id)
            ->field('revokedAt')->equals(null)
            ->field('expiresAt')->gt($now)
            ->field('readsLeft')->gt(0)
            ->field('readsLeft')->inc(-1)
            ->getQuery()
            ->execute();

        return $document instanceof SecureShareDocument ? $this->toEntity($document) : null;
    }

    /**
     * Returns all shares created by the given user.
     */
    public function findByCreator(object $creator): array
    {
        /** @var list<SecureShareDocument> $documents */
        $documents = $this->dm()->getRepository(SecureShareDocument::class)->findBy(
            ['creator' => $this->creator($creator)],
            ['createdAt' => 'DESC'],
        );

        return array_map($this->toEntity(...), $documents);
    }

    /**
     * Counts the shares created by the given user.
     */
    public function countByCreator(object $creator): int
    {
        $result = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($this->creator($creator))
            ->count()
            ->getQuery()
            ->execute();

        if (is_int($result)) {
            return $result;
        }

        return 0;
    }

    /**
     * Returns one page of shares created by the given user.
     */
    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($this->creator($creator))
            ->sort('createdAt', 'desc')
            ->limit($limit)
            ->skip($offset)
            ->getQuery()
            ->execute();

        // execute() returns a cursor iterator, not an array (array_map() threw a TypeError before 1.16).
        $shares = [];
        foreach ($documents as $document) {
            $shares[] = $this->toEntity($document);
        }

        return $shares;
    }

    /**
     * Deletes the shares of the given user created before a date; returns the number removed.
     */
    public function removeByCreatorOlderThan(object $creator, DateTimeImmutable $before): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($this->creator($creator))
            ->field('createdAt')->lt($before)
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    /**
     * Deletes every share of the given user; returns the number removed.
     */
    public function removeAllByCreator(object $creator): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($this->creator($creator))
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    /**
     * Deletes all shares created before a date; returns the number removed.
     */
    public function removeOlderThan(DateTimeImmutable $before): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->dm()->createQueryBuilder(SecureShareDocument::class)
            ->field('createdAt')->lt($before)
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    /**
     * Schedules a share for persistence.
     */
    public function persist(SecureShare $share): void
    {
        $existing = $this->dm()->find(SecureShareDocument::class, $share->getId());
        $document = $existing instanceof SecureShareDocument
            ? $this->syncDocument($existing, $share)
            : $this->fromEntity($share);

        $this->dm()->persist($document);
    }

    /**
     * Schedules a share for removal.
     */
    public function remove(SecureShare $share): void
    {
        $document = $this->dm()->find(SecureShareDocument::class, $share->getId());

        if ($document instanceof SecureShareDocument) {
            $this->dm()->remove($document);
        }
    }

    /**
     * Writes pending share changes to storage.
     */
    public function flush(): void
    {
        $this->dm()->flush();
    }

    private function fromEntity(SecureShare $share): SecureShareDocument
    {
        return SecureShareDocument::fromShare($share);
    }

    private function syncDocument(SecureShareDocument $document, SecureShare $share): SecureShareDocument
    {
        return $document->syncFrom($share);
    }

    private function toEntity(SecureShareDocument $document): SecureShare
    {
        return $document->toShare();
    }

    /**
     * @param iterable<SecureShareDocument> $documents
     */
    private function removeDocuments(iterable $documents): int
    {
        $count = 0;

        foreach ($documents as $document) {
            $this->dm()->remove($document);
            ++$count;
        }

        return $count;
    }

    private function creator(object $creator): object
    {
        return $this->creators?->reference($creator) ?? $creator;
    }

    private function now(): DateTimeImmutable
    {
        return $this->clock->now();
    }
}
