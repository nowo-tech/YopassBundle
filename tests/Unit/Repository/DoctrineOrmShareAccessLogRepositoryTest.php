<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class DoctrineOrmShareAccessLogRepositoryTest extends TestCase
{
    public function testFindByShareReturnsLogsInDescendingOrder(): void
    {
        $share = $this->createShare();
        $log   = new ShareAccessLog('00000000-0000-4000-8000-000000000071', $share, 1, '127.0.0.1', 'Agent');

        $repository = $this->createMock(EntityRepository::class);
        $repository->expects(self::once())
            ->method('findBy')
            ->with(['share' => $share], ['accessedAt' => 'DESC'], 10)
            ->willReturn([$log]);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->with(ShareAccessLog::class)->willReturn($repository);

        $logs = (new DoctrineOrmShareAccessLogRepository($entityManager))->findByShare($share, 10);

        self::assertSame([$log], $logs);
        self::assertSame('00000000-0000-4000-8000-000000000071', $log->getId());
    }

    public function testPersistAndFlushDelegateToEntityManager(): void
    {
        $share = $this->createShare();
        $log   = new ShareAccessLog('00000000-0000-4000-8000-000000000072', $share, 2);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($log);
        $entityManager->expects(self::once())->method('flush');

        $repository = new DoctrineOrmShareAccessLogRepository($entityManager);
        $repository->persist($log);
        $repository->flush();
    }

    private function createShare(): SecureShare
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000070', new TestUser());
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(2);

        return $share;
    }
}
