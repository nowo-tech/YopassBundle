<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DocumentRepository;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class DoctrineMongoShareRepositoryTest extends TestCase
{
    public function testFindReturnsMappedEntity(): void
    {
        $user     = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000001', $user);
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn($document);

        $share = (new DoctrineMongoShareRepository($documentManager))->find($document->getId());

        self::assertInstanceOf(SecureShare::class, $share);
        self::assertSame('cipher', $share?->getCiphertext());
    }

    public function testPersistAndFlushStoresDocument(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000002', $user);
        $share
            ->setCiphertext('payload')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn(null);
        $documentManager->expects(self::once())->method('persist')->with(self::isInstanceOf(SecureShareDocument::class));
        $documentManager->expects(self::once())->method('flush');

        $repository = new DoctrineMongoShareRepository($documentManager);
        $repository->persist($share);
        $repository->flush();
    }

    public function testFindReturnsNullWhenDocumentMissing(): void
    {
        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn(null);

        self::assertNull((new DoctrineMongoShareRepository($documentManager))->find('missing'));
    }

    public function testToEntityAppliesConsumedReadsAndRevocation(): void
    {
        $user     = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000006', $user);
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(2);
        $document->consumeRead();
        $document->revoke();

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn($document);

        $share = (new DoctrineMongoShareRepository($documentManager))->find($document->getId());

        self::assertNotNull($share?->getRevokedAt());
        self::assertSame(0, $share?->getReadsLeft());
    }

    public function testPersistUpdatesExistingRevokedDocument(): void
    {
        $user     = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000005', $user);
        $document
            ->setCiphertext('old')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $share = new SecureShare('00000000-0000-4000-8000-000000000005', $user);
        $share
            ->setCiphertext('new')
            ->setExpiresAt(new DateTimeImmutable('+2 hours'))
            ->setMaxReads(2)
            ->setPayloadKind('file');
        $share->revoke();

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn($document);
        $documentManager->expects(self::once())->method('persist');

        (new DoctrineMongoShareRepository($documentManager))->persist($share);
    }

    public function testFindByCreatorReturnsEntities(): void
    {
        $user     = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000003', $user);
        $document
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $repository = $this->createMock(DocumentRepository::class);
        $repository->method('findBy')->willReturn([$document]);

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('getRepository')->willReturn($repository);

        $shares = (new DoctrineMongoShareRepository($documentManager))->findByCreator($user);

        self::assertCount(1, $shares);
        self::assertSame('00000000-0000-4000-8000-000000000003', $shares[0]->getId());
    }

    public function testRemoveDeletesDocument(): void
    {
        $user     = new TestUser();
        $document = new SecureShareDocument('00000000-0000-4000-8000-000000000007', $user);

        $documentManager = $this->createMock(DocumentManager::class);
        $documentManager->method('find')->willReturn($document);
        $documentManager->expects(self::once())->method('remove')->with($document);

        $share = new SecureShare($document->getId(), $user);
        (new DoctrineMongoShareRepository($documentManager))->remove($share);
    }
}
