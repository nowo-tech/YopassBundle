<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Repository;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Entity\ShareAccessLog;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;

final class NullShareAccessLogRepositoryTest extends TestCase
{
    public function testNullRepositoryAlwaysReturnsEmptyAndAcceptsWrites(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000081', new TestUser());
        $share
            ->setCiphertext('cipher')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);
        $log = new ShareAccessLog('00000000-0000-4000-8000-000000000082', $share, 1);

        $repository = new NullShareAccessLogRepository();

        self::assertSame([], $repository->findByShare($share, 5));
        $repository->persist($log);
        $repository->flush();

        self::assertTrue(true);
    }
}
