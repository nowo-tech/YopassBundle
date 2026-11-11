<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use PHPUnit\Framework\TestCase;
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
        self::assertSame('default', $definition->getArgument('$entityManagerName'));
        self::assertTrue($definition->hasTag('kernel.event_subscriber'));
        self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
        foreach ([DoctrineOrmShareRepository::class, DoctrineOrmShareAccessLogRepository::class, DoctrineCreatorReferenceResolver::class] as $service) {
            $own = $container->getDefinition($service)->getArgument('$ownManager');
            self::assertInstanceOf(Reference::class, $own, $service);
            self::assertSame(YopassEntityManager::class, (string) $own);
        }

        $named = $this->load(['database' => ['entity_manager' => 'shares']]);
        self::assertSame('shares', $named->getDefinition(YopassEntityManager::class)->getArgument('$entityManagerName'));
    }

    public function testThePrivateEntityManagerCanBeSwitchedOffAndIsNotUsedWithTheCustomDriver(): void
    {
        $legacy = $this->load(['database' => ['private_entity_manager' => false]]);
        self::assertFalse($legacy->hasDefinition(YopassEntityManager::class));
        self::assertNull($legacy->getDefinition(DoctrineOrmShareAccessLogRepository::class)->getArgument('$ownManager'));

        $custom = $this->load(['database' => ['driver' => 'custom', 'repository' => 'app.shares', 'platform' => 'postgresql']]);
        self::assertFalse($custom->hasDefinition(YopassEntityManager::class));
        self::assertNull($custom->getDefinition(DoctrineOrmShareAccessLogRepository::class)->getArgument('$ownManager'));
    }

    public function testTheAccessCheckerIsWiredById(): void
    {
        $container = $this->load(['security' => ['access_checker' => 'app.yopass_policy']]);

        self::assertSame('app.yopass_policy', (string) $container->getAlias(YopassAccessPolicyInterface::class));
        self::assertSame('app.yopass_policy', (string) $container->getAlias(YopassAccessCheckerInterface::class));
        self::assertSame('app.yopass_policy', (string) $container->getDefinition(YopassAccessDecider::class)->getArgument(0));
        $controller = $container->getDefinition(ShareManageController::class);
        self::assertSame('app.yopass_policy', (string) $controller->getArgument('$accessChecker'));
        self::assertSame(YopassAccessDecider::class, (string) $controller->getArgument('$access'));

        $default = $this->load([]);
        self::assertSame('nowo_yopass.access_checker.default', (string) $default->getAlias(YopassAccessPolicyInterface::class));
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
