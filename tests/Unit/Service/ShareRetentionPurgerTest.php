<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    public function testPurgeAllSkipsWhenRetentionHasNoMaxAge(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeOlderThan');

        $removed = (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '']]))->purgeAll();

        self::assertSame(0, $removed);
    }

    public function testPurgeForCreatorDispatchesRetentionBulkEvent(): void
    {
        $user       = new TestUser();
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeByCreatorOlderThan')->willReturn(2);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 day']], null, $dispatcher))->purgeForCreator($user);

        self::assertCount(1, $events);
        self::assertSame(ShareBulkDeletedEvent::REASON_RETENTION, $events[0]->getReason());
        self::assertSame($user, $events[0]->getSubject());
        self::assertSame($user, $events[0]->getViewer());
        self::assertSame(2, $events[0]->getRemoved());
    }

    public function testPurgeAllDispatchesGlobalRetentionBulkEvent(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('removeOlderThan')->willReturn(5);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARES_BULK_DELETED, static function (ShareBulkDeletedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 day']], null, $dispatcher))->purgeAll();

        self::assertCount(1, $events);
        self::assertNull($events[0]->getSubject());
        self::assertNull($events[0]->getViewer());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cutoffs(): iterable
    {
        yield 'two terms' => ['1 month 2 days', '2026-10-04'];
        yield 'plus on both' => ['+1 month +2 days', '2026-10-04'];
        yield 'plus on the first' => ['+1 month 2 days', '2026-10-04'];
        yield 'comma' => ['1 month, 2 days', '2026-10-04'];
        yield 'no spaces' => ['1month2days', '2026-10-04'];
        yield 'single term' => ['14 days', '2026-10-23'];
        yield 'single term, plus' => ['+14 days', '2026-10-23'];
        yield 'one month' => ['1 month', '2026-10-06'];
        yield 'one month, plus' => ['+1 month', '2026-10-06'];
    }

    #[DataProvider('cutoffs')]
    public function testEveryTermOfMaxAgeMovesTheCutoffBack(string $maxAge, string $expected): void
    {
        $user       = new TestUser();
        $cutoff     = static fn (DateTimeImmutable $cutoff): bool => $cutoff->format('Y-m-d') === $expected;
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('removeOlderThan')->with(self::callback($cutoff))->willReturn(0);
        $repository->expects(self::once())->method('removeByCreatorOlderThan')->with($user, self::callback($cutoff))->willReturn(0);

        $purger = new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => $maxAge]], new MockClock('2026-11-06 12:00:00'));
        $purger->purgeAll();
        $purger->purgeForCreator($user);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function cutoffsNotInThePast(): iterable
    {
        yield 'negative' => ['-14 days'];
        yield 'ago' => ['1 month ago'];
        yield 'two terms, ago' => ['1 month 2 days ago'];
        yield 'zero' => ['0 days'];
        yield 'malformed' => ['first day of next month'];
    }

    #[DataProvider('cutoffsNotInThePast')]
    public function testACutoffNotInThePastDeletesNothingAndLogsAnError(string $maxAge): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeOlderThan');
        $repository->expects(self::never())->method('removeByCreatorOlderThan');
        $repository->expects(self::never())->method('flush');
        $logger = new SecretCapturingLogger();

        $purger = new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => $maxAge]], new MockClock('2026-11-06 12:00:00'), null, $logger);

        self::assertSame(0, $purger->purgeAll());
        self::assertSame(0, $purger->purgeForCreator(new TestUser()));
        self::assertCount(2, $logger->records);
        self::assertSame('error', $logger->records[0]['level']);
        self::assertSame($maxAge, $logger->records[0]['context']['max_age']);
    }

    public function testACutoffThatDependsOnTheDateIsSkippedWhenItIsNotInThePast(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('removeOlderThan');

        // Minus a month plus 30 days: from 2027-03-01 that is 2027-03-03, in the future (February has 28 days).
        $purger = new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '1 month -30 days']], new MockClock('2027-03-01 12:00:00'));

        self::assertSame(0, $purger->purgeAll());
    }

    public function testDisabledOrEmptyRetentionLogsNothing(): void
    {
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $logger     = new SecretCapturingLogger();

        (new ShareRetentionPurger($repository, ['retention' => ['enabled' => false, 'max_age' => '-14 days']], null, null, $logger))->purgeAll();
        (new ShareRetentionPurger($repository, ['retention' => ['enabled' => true, 'max_age' => '']], null, null, $logger))->purgeAll();

        self::assertSame([], $logger->records);
    }
}
