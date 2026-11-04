<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use LogicException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcher;

use const PHP_INT_MAX;

final class ShareListerTest extends TestCase
{
    public function testListUsesDefaultCreatorQuery(): void
    {
        $user  = new TestUser('viewer');
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('countByCreator')->with($user)->willReturn(1);
        $repository->expects(self::once())->method('findByCreatorPaginated')->with($user, 10, 0)->willReturn([$share]);

        $lister = new ShareLister($repository, new EventDispatcher());
        $result = $lister->list($user, 10, 1);

        self::assertSame([$share], $result['shares']);
        self::assertSame(1, $result['total']);
    }

    public function testListQueryEventCanOverrideResults(): void
    {
        $user  = new TestUser('viewer');
        $share = new SecureShare('00000000-0000-4000-8000-000000000002', new TestUser('other'));
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('findByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event) use ($share): void {
            $event->overrideList([$share], 1);
        });

        $lister = new ShareLister($repository, $dispatcher);
        $result = $lister->list($user, 0, 1);

        self::assertSame([$share], $result['shares']);
        self::assertSame(1, $result['total']);
    }

    public function testListResultEventCanFilterShares(): void
    {
        $user = new TestUser('viewer');
        $keep = new SecureShare('00000000-0000-4000-8000-000000000003', $user);
        $keep->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
        $drop = new SecureShare('00000000-0000-4000-8000-000000000004', $user);
        $drop->setCiphertext('y')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('findByCreator')->willReturn([$keep, $drop]);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_RESULT, static function (ShareListResultEvent $event) use ($keep): void {
            $event->setShares([$keep]);
            $event->setTotal(1);
        });

        $lister = new ShareLister($repository, $dispatcher);
        $result = $lister->list($user, 0, 1);

        self::assertSame([$keep], $result['shares']);
        self::assertSame(1, $result['total']);
    }

    public function testListQueryEventCarriesThePageAndPageSize(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('countByCreator')->willReturn(25);
        $repository->expects(self::once())->method('findByCreatorPaginated')->with($user, 10, 20)->willReturn([]);

        $seen       = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event) use (&$seen): void {
            $seen = [$event->getPage(), $event->getPageSize(), $event->getOffset(), $event->isPaginated()];
        });

        (new ShareLister($repository, $dispatcher))->list($user, 10, 3);

        self::assertSame([3, 10, 20, true], $seen);
    }

    public function testListQueryEventWithoutPaginationIsBackwardCompatible(): void
    {
        $user  = new TestUser('viewer');
        $event = new ShareListQueryEvent($user, $user);

        self::assertSame(1, $event->getPage());
        self::assertSame(0, $event->getPageSize());
        self::assertSame(0, $event->getOffset());
        self::assertFalse($event->isPaginated());

        $unpaginated = new ShareListQueryEvent($user, $user, 0, 5);
        self::assertSame(1, $unpaginated->getPage());

        $clamped = new ShareListQueryEvent($user, $user, -5, -2);
        self::assertSame(0, $clamped->getPageSize());
        self::assertSame(1, $clamped->getPage());
    }

    public function testListRefusesANonUserSubjectWithoutOverride(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('findByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event): void {
            $event->setListSubject(new stdClass());
        });

        $this->expectException(LogicException::class);
        (new ShareLister($repository, $dispatcher, TestUser::class))->list($user, 0, 1);
    }

    public function testListAcceptsANonUserSubjectWhenTheListenerOverrides(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event): void {
            $event->setListSubject(new stdClass());
            $event->overrideList([], 0);
        });

        self::assertSame(0, (new ShareLister($repository, $dispatcher, TestUser::class))->list($user, 0, 1)['total']);
    }

    public function testPageIsClampedSoTheOffsetNeverOverflows(): void
    {
        $user = new TestUser('viewer');

        $event = new ShareListQueryEvent($user, $user, 20, PHP_INT_MAX);
        self::assertSame(ShareListQueryEvent::MAX_PAGE, $event->getPage());
        self::assertSame((ShareListQueryEvent::MAX_PAGE - 1) * 20, $event->getOffset());

        $huge = new ShareListQueryEvent($user, $user, PHP_INT_MAX, PHP_INT_MAX);
        self::assertSame(1, $huge->getPage());
        self::assertSame(0, $huge->getOffset());

        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->method('countByCreator')->willReturn(1);
        $repository->expects(self::once())->method('findByCreatorPaginated')
            ->with($user, 20, (ShareListQueryEvent::MAX_PAGE - 1) * 20)->willReturn([]);

        (new ShareLister($repository, new EventDispatcher()))->list($user, 20, PHP_INT_MAX);
    }

    public function testOwnTotalDefaultsToTheViewerTotal(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('countByCreator')->willReturn(4);
        $repository->method('findByCreatorPaginated')->willReturn([]);

        self::assertSame(4, (new ShareLister($repository, new EventDispatcher()))->list($user, 10, 1)['own_total']);
    }

    public function testOwnTotalIsCountedWhenAListenerOverridesTheList(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::once())->method('countByCreator')->with($user)->willReturn(0);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event): void {
            $event->overrideList([], 5);
        });

        self::assertSame(0, (new ShareLister($repository, $dispatcher))->list($user, 0, 1)['own_total']);
    }

    public function testOwnTotalSetByAListenerWins(): void
    {
        $user       = new TestUser('viewer');
        $repository = $this->createMock(ShareRepositoryInterface::class);
        $repository->expects(self::never())->method('countByCreator');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event): void {
            self::assertNull($event->getOwnTotal());
            $event->overrideList([], 9);
            $event->setOwnTotal(-3);
        });

        self::assertSame(0, (new ShareLister($repository, $dispatcher))->list($user, 0, 1)['own_total']);
    }
}
