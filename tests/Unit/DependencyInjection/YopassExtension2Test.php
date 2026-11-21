<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * 2.0 (REQ-ARCH-001 rules 1, 3 and 6): the private entity manager (ORM) and the private document manager (MongoDB ODM)
 * are the only modes, the custom driver's access log goes through the bundle's own entity manager, and the 1.x
 * deprecation layer (notices, host-flush probe, `YopassAccessCheckerInterface` alias) is gone.
 */
final class YopassExtension2Test extends TestCase
{
    private const MONGO = ['driver' => 'doctrine_mongodb', 'platform' => 'mongodb'];

    private const CUSTOM = ['driver' => 'custom', 'repository' => 'app.shares', 'platform' => 'postgresql'];

    public function testTheOdmDriverAlwaysUsesThePrivateDocumentManagerWithoutANotice(): void
    {
        [$container, $deprecations] = $this->load(['database' => self::MONGO + ['document_manager' => 'shares']]);
        self::assertSame([], $deprecations);

        $definition = $container->getDefinition(YopassDocumentManager::class);
        self::assertSame('doctrine_mongodb', (string) $definition->getArgument('$registry'));
        self::assertSame('shares', $definition->getArgument('$documentManagerName'));
        self::assertSame([['method' => 'reset']], $definition->getTag('kernel.reset'));
        $boundaries = $container->getDefinition(YopassExtension::PRIVATE_DOCUMENT_MANAGER_BOUNDARIES);
        self::assertSame(YopassEntityManagerBoundarySubscriber::class, $boundaries->getClass());
        self::assertTrue($boundaries->hasTag('kernel.event_subscriber'));
        self::assertSame(YopassDocumentManager::class, (string) $boundaries->getArgument('$entityManager'));
        foreach ([DoctrineMongoShareRepository::class, DoctrineCreatorReferenceResolver::class] as $service) {
            self::assertSame(YopassDocumentManager::class, (string) $container->getDefinition($service)->getArgument('$ownManager'));
        }
        self::assertSame(YopassDocumentManager::class, (string) $container->getDefinition(HostCreatorReference::class)->getArgument(0));
        self::assertArrayNotHasKey('$registry', $container->getDefinition(DoctrineMongoShareRepository::class)->getArguments());
        self::assertFalse($container->hasDefinition(YopassEntityManager::class));
        self::assertFalse($container->hasDefinition(ShareWriteUnit::class));
    }

    public function testTheOrmDriverAlwaysUsesThePrivateEntityManagerWithoutANotice(): void
    {
        [$container, $deprecations] = $this->load([]);
        self::assertSame([], $deprecations);

        self::assertTrue($container->hasDefinition(YopassEntityManager::class));
        self::assertTrue($container->getDefinition(YopassEntityManagerBoundarySubscriber::class)->hasTag('kernel.event_subscriber'));
        foreach ([DoctrineOrmShareRepository::class, DoctrineOrmShareAccessLogRepository::class, DoctrineCreatorReferenceResolver::class] as $service) {
            self::assertSame(YopassEntityManager::class, (string) $container->getDefinition($service)->getArgument('$ownManager'));
        }
        foreach ([DoctrineOrmShareRepository::class, DoctrineOrmShareAccessLogRepository::class] as $service) {
            $arguments = $container->getDefinition($service)->getArguments();
            self::assertArrayNotHasKey('$registry', $arguments);
            self::assertArrayNotHasKey('$hostFlushProbe', $arguments);
        }
        self::assertSame(YopassEntityManager::class, (string) $container->getDefinition(HostCreatorReference::class)->getArgument(0));
        self::assertSame([YopassEntityManager::class], array_map(strval(...), $container->getDefinition(ShareWriteUnit::class)->getArguments()));
        self::assertFalse($container->hasDefinition(YopassDocumentManager::class));
    }

    public function testNoHostFlushProbeIsRegisteredAnyMore(): void
    {
        foreach ([[], ['database' => self::MONGO], ['database' => self::CUSTOM]] as $config) {
            [$container] = $this->load($config);
            self::assertFalse($container->hasDefinition('nowo_yopass.host_flush_probe'));
            self::assertFalse($container->hasDefinition('nowo_yopass.host_flush_probe.boundaries'));
        }
    }

    /**
     * @return iterable<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function optOutKeys(): iterable
    {
        yield 'ORM private_entity_manager' => ['private_entity_manager', []];
        yield 'ODM private_document_manager' => ['private_document_manager', self::MONGO];
    }

    /**
     * Owner decision Q4: `true` stays valid in 2.x as a deprecated no-op (removed in 3.0).
     *
     * @param array<string, mixed> $database
     */
    #[DataProvider('optOutKeys')]
    public function testTrueIsADeprecatedNoOp(string $key, array $database): void
    {
        [$container, $deprecations] = $this->load(['database' => $database + [$key => true]]);
        self::assertSame(['Since nowo-tech/yopass-bundle 2.0: The "' . $key . '" option at "nowo_yopass.database" has no effect since 2.0 (the private manager is the only mode); remove it.'], $deprecations);
        [$default] = $this->load(['database' => $database]);
        foreach ([YopassEntityManager::class, YopassDocumentManager::class, ShareWriteUnit::class] as $service) {
            self::assertSame($default->hasDefinition($service), $container->hasDefinition($service), $service);
        }
    }

    /**
     * Owner decision Q4: `false` (the removed host mode) fails the container build and says what to do.
     *
     * @param array<string, mixed> $database
     */
    #[DataProvider('optOutKeys')]
    public function testFalseFailsTheBuild(string $key, array $database): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/nowo_yopass\.database\.' . $key . '.*"false" is no longer supported since 2\.0.*Remove the option.*UPGRADING "To 2\.0\.0"/s');
        $this->load(['database' => $database + [$key => false]]);
    }

    /**
     * The custom driver on a relational platform: the access log is written through the bundle's own entity manager
     * on `database.entity_manager` (same connection and table) and the application's manager is never flushed.
     */
    public function testTheCustomDriversAccessLogGoesThroughTheBundlesEntityManager(): void
    {
        [$container, $deprecations] = $this->load(['database' => self::CUSTOM]);
        self::assertSame([], $deprecations);
        self::assertSame('app.shares', (string) $container->getAlias(ShareRepositoryInterface::class));
        self::assertSame(DoctrineOrmShareAccessLogRepository::class, (string) $container->getAlias(ShareAccessLogRepositoryInterface::class));
        self::assertSame(YopassEntityManager::class, (string) $container->getDefinition(DoctrineOrmShareAccessLogRepository::class)->getArgument('$ownManager'));
        self::assertSame('default', $container->getDefinition(YopassEntityManager::class)->getArgument('$entityManagerName'));
        self::assertTrue($container->getDefinition(YopassEntityManagerBoundarySubscriber::class)->hasTag('kernel.event_subscriber'));
        // The shares come from the host repository: no bundle write unit.
        self::assertFalse($container->hasDefinition(ShareWriteUnit::class));
        // 2.0.1: the host repository may decorate the bundle's one on the private manager, so the events expose the
        // creator in the application's manager (a creator the private manager does not hold is returned unchanged).
        self::assertSame(YopassEntityManager::class, (string) $container->getDefinition(HostCreatorReference::class)->getArgument(0));

        [$hostLog] = $this->load(['database' => self::CUSTOM, 'access_log' => ['repository' => 'app.access_log']]);
        self::assertSame('app.access_log', (string) $hostLog->getAlias(ShareAccessLogRepositoryInterface::class));

        [$nonRelational] = $this->load(['database' => ['driver' => 'custom', 'repository' => 'app.shares', 'platform' => 'mongodb']]);
        self::assertFalse($nonRelational->hasDefinition(YopassEntityManager::class), 'a non-relational platform has no bundle access log');
        self::assertFalse($nonRelational->hasDefinition(HostCreatorReference::class), 'no private manager, no creator to hand back');
    }

    public function testOnlyThePolicyAliasIsRegistered(): void
    {
        [$container] = $this->load([]);
        self::assertFalse($container->hasAlias('Nowo\YopassBundle\Security\YopassAccessCheckerInterface'));
        self::assertFalse($container->getAlias(YopassAccessPolicyInterface::class)->isDeprecated());
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{0: ContainerBuilder, 1: list<string>}
     */
    private function load(array $config): array
    {
        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $container = new ContainerBuilder();
            (new YopassExtension())->load([['user_class' => 'App\Entity\User'] + $config], $container);
        } finally {
            restore_error_handler();
        }

        return [$container, $deprecations];
    }
}
