<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($this->createFindQueryBuilder($share));
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

    public function testConsumeReadIfAvailableReloadsShareWithoutClearingEntityManager(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000009', $user);
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(3);
        $share->consumeRead();

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('clear');
        $entityManager->method('createQueryBuilder')->willReturnOnConsecutiveCalls(
            $this->createExecuteQueryBuilder(1),
            $this->createFindQueryBuilder($share),
        );

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

    public function testFindRefreshesManagedShareOnEveryCall(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000010', new TestUser());

        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHint', 'getOneOrNullResult'])
            ->getMock();
        $query->expects(self::exactly(2))->method('setHint')->with(Query::HINT_REFRESH, true)->willReturnSelf();
        $query->expects(self::exactly(2))->method('getOneOrNullResult')->willReturnOnConsecutiveCalls($share, null);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('createQueryBuilder')->willReturn($this->createSelectQueryBuilder($query));

        $repository = new DoctrineOrmShareRepository($entityManager);

        // Request 1: share exists.
        self::assertSame($share, $repository->find($share->getId()));
        // Request 2 (same repository, no reset): deleted by another worker; the stale instance is not returned.
        self::assertNull($repository->find($share->getId()));
    }

    public function testClosedEntityManagerIsResetOnNextRequestWithoutKernelReset(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000011', new TestUser());

        $closed = $this->createMock(EntityManagerInterface::class);
        $closed->method('isOpen')->willReturnOnConsecutiveCalls(true, false);
        $closed->expects(self::once())->method('flush')->willThrowException(new RuntimeException('connection lost'));
        $closed->expects(self::never())->method('persist');

        $fresh = $this->createMock(EntityManagerInterface::class);
        $fresh->method('isOpen')->willReturn(true);
        $fresh->expects(self::once())->method('persist')->with($share);

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->with('yopass')->willReturn($closed);
        $registry->expects(self::once())->method('resetManager')->with('yopass')->willReturn($fresh);

        $repository = new DoctrineOrmShareRepository($closed, null, $registry, 'yopass');

        try {
            $repository->flush();
            self::fail('Expected flush failure.');
        } catch (RuntimeException $e) {
            self::assertSame('connection lost', $e->getMessage());
        }

        $repository->persist($share);
    }

    public function testFallsBackToInjectedEntityManagerWhenRegistryReturnsAnotherManagerType(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');

        $registry = $this->createMock(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createMock(ObjectManager::class));

        (new DoctrineOrmShareRepository($entityManager, null, $registry, 'default'))->flush();
    }

    private function createFindQueryBuilder(?SecureShare $result): QueryBuilder
    {
        $query = $this->getMockBuilder(Query::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setHint', 'getOneOrNullResult'])
            ->getMock();
        $query->expects(self::once())->method('setHint')->with(Query::HINT_REFRESH, true)->willReturnSelf();
        $query->expects(self::once())->method('getOneOrNullResult')->willReturn($result);

        return $this->createSelectQueryBuilder($query);
    }

    private function createSelectQueryBuilder(Query $query): QueryBuilder
    {
        $queryBuilder = $this->getMockBuilder(QueryBuilder::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['select', 'from', 'where', 'setParameter', 'getQuery'])
            ->getMock();
        $queryBuilder->method('select')->willReturnSelf();
        $queryBuilder->method('from')->willReturnSelf();
        $queryBuilder->method('where')->willReturnSelf();
        $queryBuilder->method('setParameter')->willReturnSelf();
        $queryBuilder->method('getQuery')->willReturn($query);

        return $queryBuilder;
    }

    private function mockQueryBuilderExecution(EntityManagerInterface $entityManager, int $result): void
    {
        $entityManager->method('createQueryBuilder')->willReturn($this->createExecuteQueryBuilder($result));
    }

    private function createExecuteQueryBuilder(int $result): QueryBuilder
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

        return $queryBuilder;
    }
}
