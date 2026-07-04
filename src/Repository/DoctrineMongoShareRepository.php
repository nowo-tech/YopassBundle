<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Doctrine MongoDB ODM implementation for MongoDB deployments.
 */
final class DoctrineMongoShareRepository implements ShareRepositoryInterface
{
    public function __construct(
        private readonly DocumentManager $documentManager,
    ) {
    }

    public function find(string $id): ?SecureShare
    {
        $document = $this->documentManager->find(SecureShareDocument::class, $id);

        return $document instanceof SecureShareDocument ? $this->toEntity($document) : null;
    }

    public function findByCreator(object $creator): array
    {
        /** @var list<SecureShareDocument> $documents */
        $documents = $this->documentManager->getRepository(SecureShareDocument::class)->findBy(
            ['creator' => $creator],
            ['createdAt' => 'DESC'],
        );

        return array_map(fn (SecureShareDocument $document): SecureShare => $this->toEntity($document), $documents);
    }

    public function countByCreator(object $creator): int
    {
        return (int) $this->documentManager->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($creator)
            ->count()
            ->getQuery()
            ->execute();
    }

    public function findByCreatorPaginated(object $creator, int $limit, int $offset): array
    {
        /** @var list<SecureShareDocument> $documents */
        $documents = $this->documentManager->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($creator)
            ->sort('createdAt', 'desc')
            ->limit($limit)
            ->skip($offset)
            ->getQuery()
            ->execute();

        return array_map(fn (SecureShareDocument $document): SecureShare => $this->toEntity($document), $documents);
    }

    public function removeByCreatorOlderThan(object $creator, \DateTimeImmutable $before): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->documentManager->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($creator)
            ->field('createdAt')->lt($before)
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    public function removeAllByCreator(object $creator): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->documentManager->createQueryBuilder(SecureShareDocument::class)
            ->field('creator')->equals($creator)
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    public function removeOlderThan(\DateTimeImmutable $before): int
    {
        /** @var iterable<SecureShareDocument> $documents */
        $documents = $this->documentManager->createQueryBuilder(SecureShareDocument::class)
            ->field('createdAt')->lt($before)
            ->getQuery()
            ->execute();

        return $this->removeDocuments($documents);
    }

    public function persist(SecureShare $share): void
    {
        $existing = $this->documentManager->find(SecureShareDocument::class, $share->getId());
        $document = $existing instanceof SecureShareDocument
            ? $this->syncDocument($existing, $share)
            : $this->fromEntity($share);

        $this->documentManager->persist($document);
    }

    public function remove(SecureShare $share): void
    {
        $document = $this->documentManager->find(SecureShareDocument::class, $share->getId());

        if ($document instanceof SecureShareDocument) {
            $this->documentManager->remove($document);
        }
    }

    public function flush(): void
    {
        $this->documentManager->flush();
    }

    private function fromEntity(SecureShare $share): SecureShareDocument
    {
        $document = new SecureShareDocument($share->getId(), $share->getCreator());
        $document
            ->setCiphertext($share->getCiphertext())
            ->setExpiresAt($share->getExpiresAt())
            ->setMaxReads($share->getMaxReads())
            ->setPayloadKind($share->getPayloadKind());

        return $document;
    }

    private function syncDocument(SecureShareDocument $document, SecureShare $share): SecureShareDocument
    {
        $document
            ->setCiphertext($share->getCiphertext())
            ->setExpiresAt($share->getExpiresAt())
            ->setMaxReads($share->getMaxReads())
            ->setPayloadKind($share->getPayloadKind());

        if ($share->getRevokedAt() !== null) {
            $document->revoke();
        }

        return $document;
    }

    private function toEntity(SecureShareDocument $document): SecureShare
    {
        $share = new SecureShare($document->getId(), $document->getCreator());
        $share
            ->setCiphertext($document->getCiphertext())
            ->setExpiresAt($document->getExpiresAt())
            ->setMaxReads($document->getMaxReads())
            ->setPayloadKind($document->getPayloadKind());

        for ($consumed = $document->getMaxReads() - $document->getReadsLeft(); $consumed > 0; --$consumed) {
            $share->consumeRead();
        }

        if ($document->getRevokedAt() !== null) {
            $share->revoke();
        }

        return $share;
    }

    /**
     * @param iterable<SecureShareDocument> $documents
     */
    private function removeDocuments(iterable $documents): int
    {
        $count = 0;

        foreach ($documents as $document) {
            $this->documentManager->remove($document);
            ++$count;
        }

        return $count;
    }
}
