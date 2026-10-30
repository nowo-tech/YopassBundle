<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\YopassBundle\DependencyInjection\Compiler\ManageWebUiSecurityPass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Extension\Extension;

final class ManageWebUiSecurityPassTest extends TestCase
{
    public function testNoOpWhenWebUiParameterIsMissing(): void
    {
        $container = new ContainerBuilder();

        (new ManageWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasParameter('nowo_yopass.web_ui.enabled'));
    }

    public function testNoOpWhenWebUiIsDisabled(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.web_ui.enabled', false);

        (new ManageWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasExtension('security'));
    }

    public function testNoOpWhenUnauthenticatedModeIsAllowed(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.web_ui.enabled', true);
        $container->setParameter('nowo_yopass.security', ['allow_unauthenticated' => true]);

        (new ManageWebUiSecurityPass())->process($container);

        self::assertFalse($container->hasExtension('security'));
    }

    public function testFailsWhenSecurityBundleIsRequiredButMissing(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.web_ui.enabled', true);
        $container->setParameter('nowo_yopass.security', ['allow_unauthenticated' => false, 'access_roles' => ['ROLE_ADMIN']]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('SecurityBundle');

        (new ManageWebUiSecurityPass())->process($container);
    }

    public function testFailsWhenEveryRoleListIsEmpty(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_yopass.web_ui.enabled', true);
        $container->setParameter('nowo_yopass.security', [
            'allow_unauthenticated' => false,
            'access_checker'        => null,
            'access_roles'          => [],
            'roles'                 => ['admin' => [], 'create' => [], 'list' => [], 'revoke' => []],
        ]);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('role list');

        (new ManageWebUiSecurityPass())->process($container);
    }

    public function testEmptyRoleListsAreAcceptedWithACustomAccessChecker(): void
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'security';
            }
        });
        $container->setParameter('nowo_yopass.web_ui.enabled', true);
        $container->setParameter('nowo_yopass.security', [
            'allow_unauthenticated' => false,
            'access_checker'        => 'app.yopass_checker',
            'access_roles'          => [],
            'roles'                 => ['admin' => [], 'create' => [], 'list' => [], 'revoke' => []],
        ]);

        (new ManageWebUiSecurityPass())->process($container);

        $this->addToAssertionCount(1);
    }
}
