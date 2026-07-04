<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
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
}
