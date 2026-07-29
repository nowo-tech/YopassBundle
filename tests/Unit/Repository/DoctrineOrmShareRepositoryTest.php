<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class DoctrineOrmShareRepositoryTest extends TestCase
{
    public function testFindByCreatorReturnsShares(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', $user);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findBy')->willReturn([$share]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $shares = (new DoctrineOrmShareRepository($entityManager))->findByCreator($user);

        self::assertSame([$share], $shares);
    }

    public function testFindPersistAndFlush(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000004', $user);

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('find')->willReturn($share);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('persist')->with($share);
        $entityManager->expects(self::once())->method('flush');

        $ormRepository = new DoctrineOrmShareRepository($entityManager);
        self::assertSame($share, $ormRepository->find($share->getId()));
        $ormRepository->persist($share);
        $ormRepository->flush();
    }

    public function testRemoveDeletesManagedShare(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000005', $user);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn($share);
        $entityManager->expects(self::once())->method('remove')->with($share);

        (new DoctrineOrmShareRepository($entityManager))->remove($share);
    }

    public function testRemoveSkipsMissingManagedShare(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000006', new TestUser());

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('find')->willReturn(null);
        $entityManager->expects(self::never())->method('remove');

        (new DoctrineOrmShareRepository($entityManager))->remove($share);
    }

    public function testCountByCreatorDelegatesToRepository(): void
    {
        $user       = new TestUser();
        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('count')->with(['creator' => $user])->willReturn(3);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        self::assertSame(3, (new DoctrineOrmShareRepository($entityManager))->countByCreator($user));
    }

    public function testFindByCreatorPaginatedReturnsShares(): void
    {
        $user   = new TestUser();
        $share1 = new SecureShare('00000000-0000-4000-8000-000000000007', $user);
        $share2 = new SecureShare('00000000-0000-4000-8000-000000000008', $user);

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['creator' => $user], ['createdAt' => 'DESC'], 10, 20)
            ->willReturn([$share1, $share2]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);

        $shares = (new DoctrineOrmShareRepository($entityManager))->findByCreatorPaginated($user, 10, 20);

        self::assertSame([$share1, $share2], $shares);
    }

    public function testConsumeReadIfAvailableReturnsNullWhenNothingWasUpdated(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('clear');
        $this->mockQueryBuilderExecution($entityManager, 0);

        self::assertNull((new DoctrineOrmShareRepository($entityManager))->consumeReadIfAvailable('missing'));
    }

    public function testConsumeReadIfAvailableClearsEntityManagerAndReloadsShare(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000009', $user);
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(3);
        $share->consumeRead();

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())->method('find')->with($share->getId())->willReturn($share);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->expects(self::once())->method('clear');
        $this->mockQueryBuilderExecution($entityManager, 1);

        $result = (new DoctrineOrmShareRepository($entityManager))->consumeReadIfAvailable($share->getId());

        self::assertSame($share, $result);
    }

    public function testRemoveByCreatorOlderThanReturnsDeletedRows(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mockQueryBuilderExecution($entityManager, 4);

        $removed = (new DoctrineOrmShareRepository($entityManager))->removeByCreatorOlderThan(new TestUser(), new DateTimeImmutable('-1 day'));

        self::assertSame(4, $removed);
    }

    public function testRemoveAllByCreatorReturnsDeletedRows(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mockQueryBuilderExecution($entityManager, 2);

        $removed = (new DoctrineOrmShareRepository($entityManager))->removeAllByCreator(new TestUser());

        self::assertSame(2, $removed);
    }

    public function testRemoveOlderThanReturnsDeletedRows(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $this->mockQueryBuilderExecution($entityManager, 5);

        $removed = (new DoctrineOrmShareRepository($entityManager))->removeOlderThan(new DateTimeImmutable('-1 week'));

        self::assertSame(5, $removed);
    }

    private function mockQueryBuilderExecution(EntityManagerInterface $entityManager, int $result): void
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['execute'])
            ->getMock();
        $query->expects(self::once())->method('execute')->willReturn($result);

        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update', 'delete', 'set', 'where', 'andWhere', 'setParameter', 'getQuery'])
            ->getMock();
        $queryBuilder->method('update')->willReturnSelf();
        $queryBuilder->method('delete')->willReturnSelf();
        $queryBuilder->method('set')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('andWhere')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        $entityManager->method('createQueryBuilder')->willReturn($queryBuilder);
    }
}
