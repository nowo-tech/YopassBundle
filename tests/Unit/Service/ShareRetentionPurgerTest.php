<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ShareRetentionPurgerTest extends TestCase
{
    public function testPurgeForCreatorSkipsWhenDisabled(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeByCreatorOlderThan');

        $removed = (new ShareRetentionPurger($repository, ['retention' => ['enabled' => false, 'max_age' => '1 month']]))->purgeForCreator(new TestUser());

        self::assertSame(0, $removed);
    }

    public function testPurgeForCreatorRemovesAndFlushes(): void
    {
        $user       = new TestUser();
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())
            ->method('removeByCreatorOlderThan')
            ->with($user, self::callback(static fn (DateTimeImmutable $cutoff): bool => $cutoff->format('Y-m-d') === '2026-06-27'))
            ->willReturn(3);
        $repository->expects(self::once())->method('flush');

        $clock   = new MockClock('2026-07-27 12:00:00');
        $removed = (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 month']], $clock))->purgeForCreator($user);

        self::assertSame(3, $removed);
    }

    public function testPurgeAllSkipsFlushWhenNothingRemoved(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeOlderThan')->willReturn(0);
        $repository->expects(self::never())->method('flush');

        $removed = (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '30 days']]))->purgeAll();

        self::assertSame(0, $removed);
    }
}
