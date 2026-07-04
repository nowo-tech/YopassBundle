<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use DateTimeImmutable;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
}

final class ShareAccessGuardTest extends TestCase
{
    public function testCreatorIsGrantedByDefault(): void
    {
        $user  = new TestUser('owner');
        $share = new SecureShare('00000000-0000-4000-8000-000000000005', $user);

        $guard = new ShareAccessGuard(new EventDispatcher());

        self::assertTrue($guard->canManage($user, $share, ShareAccessAction::Preview));
    }

    public function testNonCreatorIsDeniedByDefault(): void
    {
        $owner = new TestUser('owner');
        $other = new TestUser('other');
        $share = new SecureShare('00000000-0000-4000-8000-000000000006', $owner);

        $guard = new ShareAccessGuard(new EventDispatcher());

        self::assertFalse($guard->canManage($other, $share, ShareAccessAction::Preview));
    }

    public function testAccessCheckEventCanGrantNonCreator(): void
    {
        $owner = new TestUser('owner');
        $other = new TestUser('other');
        $share = new SecureShare('00000000-0000-4000-8000-000000000007', $owner);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_ACCESS_CHECK, static function (ShareAccessCheckEvent $event): void {
            $event->grant();
        });

        $guard = new ShareAccessGuard($dispatcher);

        self::assertTrue($guard->canManage($other, $share, ShareAccessAction::View));
    }
}
