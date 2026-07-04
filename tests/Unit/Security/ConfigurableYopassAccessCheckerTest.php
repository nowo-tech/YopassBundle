<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class ConfigurableYopassAccessCheckerTest extends TestCase
{
    public function testAdminBypassesFeatureChecks(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_ADMIN',
        );

        $checker = new ConfigurableYopassAccessChecker(
            $security,
            ['ROLE_ADMIN'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_USER'],
        );

        self::assertTrue($checker->canAccess());
        self::assertTrue($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertTrue($checker->canRevoke());
    }

    public function testUserRoleGrantsCreateWhenConfigured(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_USER',
        );

        $checker = new ConfigurableYopassAccessChecker(
            $security,
            ['ROLE_ADMIN'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_SPECIAL'],
        );

        self::assertTrue($checker->canCreate());
        self::assertFalse($checker->canRevoke());
    }

    public function testAccessAndListRespectConfiguredRoles(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (string $role): bool => $role === 'ROLE_LIST_ONLY',
        );

        $checker = new ConfigurableYopassAccessChecker(
            $security,
            ['ROLE_ADMIN'],
            ['ROLE_ACCESS'],
            ['ROLE_CREATE'],
            ['ROLE_LIST_ONLY'],
            ['ROLE_REVOKE'],
        );

        self::assertFalse($checker->canAccess());
        self::assertFalse($checker->canCreate());
        self::assertTrue($checker->canList());
        self::assertFalse($checker->canRevoke());
    }

    public function testNoRolesDeniesEverythingForNonAdmin(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(false);

        $checker = new ConfigurableYopassAccessChecker(
            $security,
            ['ROLE_ADMIN'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_USER'],
            ['ROLE_USER'],
        );

        self::assertFalse($checker->canAccess());
        self::assertFalse($checker->canCreate());
        self::assertFalse($checker->canList());
        self::assertFalse($checker->canRevoke());
    }
}
