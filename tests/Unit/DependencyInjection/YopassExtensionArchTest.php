<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\Compiler\AccessCheckerAliasPass;
use Nowo\YopassBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function dirname;
use function file_get_contents;
use function realpath;

/**
 * Container wiring of the 1.19.0 architecture changes: the bundle's own entity manager (rule 6), the access policy
 * port (rule 3) and the XML mapping entries (rule 2).
 */
final class YopassExtensionArchTest extends TestCase
{
    public function testThePrivateEntityManagerIsTheDefaultOfTheOrmDriver(): void
    {
        $container = $this->load([]);

        $definition = $container->getDefinition(YopassEntityManager::class);
        self::assertSame(YopassEntityManager::class, (string) $container->getDefinition(HostCreatorReference::class)->getArgument(0));
        self::assertSame('default', $definition->getArgument('$entityManagerName'));
        self::assertFalse($definition->hasTag('kernel.event_subscriber'), '1.20.0: the boundary subscriber drops it');
        self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
        $subscriber = $container->getDefinition(YopassEntityManagerBoundarySubscriber::class);
        self::assertTrue($subscriber->hasTag('kernel.event_subscriber'));
        self::assertSame(YopassEntityManager::class, (string) $subscriber->getArgument('$entityManager'));
        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            self::assertSame('default', $container->getParameter(PrivateEntityManagerPass::MANAGER_PARAMETER));
            self::assertSame(YopassEntityManager::class, (string) $container->getDefinition(PrivateEntityManagerMiddleware::class)->getArgument('$entityManager'));
        }
        foreach ([DoctrineOrmShareRepository::class, DoctrineOrmShareAccessLogRepository::class, DoctrineCreatorReferenceResolver::class] as $service) {
            $own = $container->getDefinition($service)->getArgument('$ownManager');
            self::assertInstanceOf(Reference::class, $own, $service);
            self::assertSame(YopassEntityManager::class, (string) $own);
        }

        $named = $this->load(['database' => ['entity_manager' => 'shares']]);
        self::assertSame('shares', $named->getDefinition(YopassEntityManager::class)->getArgument('$entityManagerName'));
    }

    public function testTheAccessCheckerIsWiredById(): void
    {
        $container = $this->load(['security' => ['access_checker' => 'app.yopass_policy']]);

        self::assertSame('app.yopass_policy', (string) $container->getAlias(YopassAccessPolicyInterface::class));
        self::assertSame('app.yopass_policy', (string) $container->getDefinition(YopassAccessDecider::class)->getArgument(0));
        $controller = $container->getDefinition(ShareManageController::class);
        self::assertSame('app.yopass_policy', (string) $controller->getArgument('$accessChecker'));
        self::assertSame(YopassAccessDecider::class, (string) $controller->getArgument('$access'));

        $default = $this->load([]);
        self::assertSame('nowo_yopass.access_checker.default', (string) $default->getAlias(YopassAccessPolicyInterface::class));
    }

    public function testAPolicyCheckerPassesTheBuildCheck(): void
    {
        $policy = new class implements YopassAccessPolicyInterface {
            public function canAccess(?UserRef $user = null): bool
            {
                return false;
            }

            public function canCreate(?UserRef $user = null): bool
            {
                return false;
            }

            public function canList(?UserRef $user = null): bool
            {
                return false;
            }

            public function canRevoke(?UserRef $user = null): bool
            {
                return false;
            }
        };
        self::assertTrue($this->checked($policy::class)->hasAlias(YopassAccessPolicyInterface::class));
        self::assertTrue($this->checked(ConfigurableYopassAccessChecker::class)->hasAlias(YopassAccessPolicyInterface::class));
        self::assertTrue($this->checked('%checker.class%', ['checker.class' => ConfigurableYopassAccessChecker::class])->hasAlias(YopassAccessPolicyInterface::class));

        // No alias, an alias to a service defined later by the host, a factory or a class-less id: left alone.
        $container = new ContainerBuilder();
        (new AccessCheckerAliasPass())->process($container);
        self::assertFalse($container->hasAlias(YopassAccessPolicyInterface::class));
        $container->setAlias(YopassAccessPolicyInterface::class, 'app.not_yet_defined');
        (new AccessCheckerAliasPass())->process($container);
        self::assertTrue($container->hasAlias(YopassAccessPolicyInterface::class));
        $container = new ContainerBuilder();
        $container->register('app.checker', stdClass::class)->setFactory([stdClass::class, 'create']);
        $container->setAlias(YopassAccessPolicyInterface::class, 'app.checker');
        (new AccessCheckerAliasPass())->process($container);
        self::assertTrue($container->hasAlias(YopassAccessPolicyInterface::class));
        self::assertTrue($this->checked(null, [], 'app_checker')->hasAlias(YopassAccessPolicyInterface::class));
        self::assertTrue($this->checked('%checker.class%', ['checker.class' => ['not', 'a', 'class']])->hasAlias(YopassAccessPolicyInterface::class));
    }

    /**
     * 2.0: a configured checker that does not implement YopassAccessPolicyInterface fails the build.
     */
    public function testACheckerWithoutThePolicyInterfaceFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The "nowo_yopass.security.access_checker" service "app.checker" (class "stdClass") must implement "Nowo\YopassBundle\Security\YopassAccessPolicyInterface" since 2.0 ("YopassAccessCheckerInterface" was removed); see UPGRADING "To 2.0.0".');
        $this->checked(stdClass::class);
    }

    /**
     * 2.0: a checker class that cannot be loaded (it still implements the removed YopassAccessCheckerInterface) fails the
     * build with a message naming it, instead of a fatal error at the first request.
     */
    public function testACheckerThatCannotBeLoadedFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The class "App\Missing\Checker" of the "nowo_yopass.security.access_checker" service "app.checker" cannot be loaded. If it still implements "Nowo\YopassBundle\Security\YopassAccessCheckerInterface" (removed in 2.0), implement "Nowo\YopassBundle\Security\YopassAccessPolicyInterface" instead; see UPGRADING "To 2.0.0".');
        $this->checked('App\Missing\Checker');
    }

    /**
     * @param array<string, list<string>|string> $parameters
     */
    private function checked(?string $class, array $parameters = [], string $id = 'app.checker'): ContainerBuilder
    {
        $container = new ContainerBuilder();
        foreach ($parameters as $name => $value) {
            $container->setParameter($name, $value);
        }
        $container->register($id, $class);
        $container->setAlias(YopassAccessPolicyInterface::class, $id);
        (new AccessCheckerAliasPass())->process($container);

        return $container;
    }

    public function testOrmMappingEntries(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Resources/config/doctrine/';

        self::assertSame(['YopassBundle' => ['type' => 'attribute', 'is_bundle' => true]], YopassExtension::ormMappings(null));
        self::assertSame(['YopassBundle' => ['type' => 'attribute', 'is_bundle' => true]], YopassExtension::ormMappings(['database' => 'nope']));
        self::assertSame(['YopassBundle' => ['type' => 'attribute', 'is_bundle' => true]], YopassExtension::ormMappings(['database' => ['mapping' => 'attribute']]));

        $xml = static fn (string $sub): array => ['YopassBundle' => ['type' => 'xml', 'is_bundle' => false, 'dir' => (string) realpath($dir . $sub), 'prefix' => 'Nowo\YopassBundle\Entity']];
        self::assertSame($xml('shares_and_access_log'), $this->real(YopassExtension::ormMappings(['database' => ['mapping' => 'xml']])));
        self::assertSame($xml('shares_and_access_log'), $this->real(YopassExtension::ormMappings(['database' => ['mapping' => 'xml', 'driver' => 'custom', 'platform' => 'mysql']])));
        self::assertSame($xml('shares_only'), $this->real(YopassExtension::ormMappings(['database' => ['mapping' => 'xml'], 'access_log' => ['enabled' => false]])));
        self::assertSame($xml('shares_only'), $this->real(YopassExtension::ormMappings(['database' => ['mapping' => 'xml'], 'access_log' => ['enabled' => true, 'repository' => 'app.log']])));
        self::assertSame($xml('shares_only'), $this->real(YopassExtension::ormMappings(['database' => ['mapping' => 'xml', 'driver' => 'custom', 'platform' => 'mongodb']])));

        self::assertSame(
            file_get_contents($dir . 'shares_and_access_log/SecureShare.orm.xml'),
            file_get_contents($dir . 'shares_only/SecureShare.orm.xml'),
            'The two SecureShare mappings must stay identical',
        );
    }

    /**
     * @param array<string, array<string, mixed>> $mappings
     *
     * @return array<string, array<string, mixed>>
     */
    private function real(array $mappings): array
    {
        $mappings['YopassBundle']['dir'] = (string) realpath((string) $mappings['YopassBundle']['dir']);

        return $mappings;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new YopassExtension())->load([['user_class' => 'App\Entity\User'] + $config], $container);

        return $container;
    }
}
