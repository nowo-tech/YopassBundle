<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
