<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
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

    public function testAnotherIdentityDomainWithTheSameIdIsNotTheOwner(): void
    {
        $customer = new GuardUser(5, 'customer');
        $share    = new SecureShare('00000000-0000-4000-8000-000000000008', $customer);
        $policy   = new CreatorClassPolicy(GuardUser::class, [SecurityUserDto::class]);
        $guard    = new ShareAccessGuard(new EventDispatcher(), $policy);

        self::assertTrue($guard->canManage($customer, $share, ShareAccessAction::Delete));
        self::assertFalse($guard->canManage(new TestUser('5'), $share, ShareAccessAction::Delete), 'Another class with the same id');
        self::assertTrue($guard->canManage(new SecurityUserDto(5), $share, ShareAccessAction::Delete), 'Listed wrapper');
        self::assertFalse((new ShareAccessGuard(new EventDispatcher(), new CreatorClassPolicy(GuardUser::class)))->canManage(new SecurityUserDto(5), $share, ShareAccessAction::Delete), 'Not listed');

        $zero = new SecureShare('00000000-0000-4000-8000-000000000009', new GuardUser(0, 'zero'));
        self::assertFalse($guard->canManage(new GuardUser(0, 'zero'), $zero, ShareAccessAction::Delete), 'Id 0 is refused');
    }
}
