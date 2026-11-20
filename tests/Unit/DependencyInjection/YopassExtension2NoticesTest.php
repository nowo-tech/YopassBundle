<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\TransactionDoctrine\HostFlushProbe;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function restore_error_handler;
use function set_error_handler;
use function sprintf;

use const E_USER_DEPRECATED;

/**
 * The 1.22.0 deprecation layer before 2.0 (REQ-ARCH-001): the MongoDB ODM notice and opt-in
 * (`database.private_document_manager`), the host-flush probe of the ORM host mode, and the deprecated
 * `YopassAccessCheckerInterface` alias.
 */
final class YopassExtension2NoticesTest extends TestCase
{
    private const ODM_NOTICE = 'Since nowo-tech/yopass-bundle 1.22.0: The MongoDB ODM driver of nowo-tech/yopass-bundle flushes the application\'s document manager "%s" when it stores shares, which also writes any change the application left pending on it. In 2.0 the bundle always writes through its own document manager (same database and collection). Opt in now: set "nowo_yopass.database.private_document_manager: true".';

    private const MONGO = ['driver' => 'doctrine_mongodb', 'platform' => 'mongodb'];

    public function testTheOdmDriverWithoutTheOptInTriggersTheNotice(): void
    {
        [$container, $deprecations] = $this->load(['database' => self::MONGO]);
        self::assertSame([sprintf(self::ODM_NOTICE, 'default')], $deprecations);
        self::assertFalse($container->hasDefinition(YopassDocumentManager::class));
        self::assertNull($container->getDefinition(DoctrineMongoShareRepository::class)->getArguments()['$ownManager'] ?? null);
        self::assertFalse($container->hasDefinition(HostCreatorReference::class));

        [, $deprecations] = $this->load(['database' => self::MONGO + ['document_manager' => 'shares', 'private_document_manager' => false]]);
        self::assertSame([sprintf(self::ODM_NOTICE, 'shares')], $deprecations, 'an explicit false is the deprecated mode too');
    }

    public function testTheOptInRegistersThePrivateDocumentManagerWithoutANotice(): void
    {
        [$container, $deprecations] = $this->load(['database' => self::MONGO + ['document_manager' => 'shares', 'private_document_manager' => true]]);
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
        self::assertFalse($container->hasDefinition(YopassExtension::HOST_FLUSH_PROBE), 'the probe is ORM-only');
    }

    public function testTheOrmDriversDefaultTriggersNoNewNoticeAndHasNoProbe(): void
    {
        [$container, $deprecations] = $this->load([]);
        self::assertSame([], $deprecations);
        self::assertFalse($container->hasDefinition(YopassExtension::HOST_FLUSH_PROBE));
        self::assertNull($container->getDefinition(DoctrineOrmShareRepository::class)->getArguments()['$hostFlushProbe'] ?? null);
        self::assertNull($container->getDefinition(DoctrineOrmShareAccessLogRepository::class)->getArguments()['$hostFlushProbe'] ?? null);
        self::assertFalse($container->hasDefinition(YopassDocumentManager::class));
    }

    public function testTheOrmHostModeWiresTheProbeResetAtEveryBoundary(): void
    {
        [$container, $deprecations] = $this->load(['database' => ['private_entity_manager' => false]]);
        self::assertSame(['Since nowo-tech/yopass-bundle 1.19.0: Setting "nowo_yopass.database.private_entity_manager" to false is deprecated: the bundle then flushes the application\'s entity manager. Remove the option; it is removed in 2.0.'], $deprecations);

        $probe = $container->getDefinition(YopassExtension::HOST_FLUSH_PROBE);
        self::assertSame(HostFlushProbe::class, $probe->getClass());
        self::assertSame([['method' => 'reset']], $probe->getTag('kernel.reset'));
        $boundaries = $container->getDefinition(YopassExtension::HOST_FLUSH_PROBE_BOUNDARIES);
        self::assertTrue($boundaries->hasTag('kernel.event_subscriber'));
        self::assertSame(YopassExtension::HOST_FLUSH_PROBE, (string) $boundaries->getArgument('$entityManager'));
        foreach ([DoctrineOrmShareRepository::class, DoctrineOrmShareAccessLogRepository::class] as $service) {
            self::assertSame(YopassExtension::HOST_FLUSH_PROBE, (string) $container->getDefinition($service)->getArgument('$hostFlushProbe'));
        }

        // The custom driver keeps its access log on the application's manager as before: no probe there in 1.22.
        [$custom] = $this->load(['database' => ['driver' => 'custom', 'repository' => 'app.shares', 'platform' => 'postgresql', 'private_entity_manager' => false]]);
        self::assertFalse($custom->hasDefinition(YopassExtension::HOST_FLUSH_PROBE));
    }

    public function testTheLegacyAccessCheckerAliasIsDeprecatedAndThePolicyAliasIsNot(): void
    {
        [$container] = $this->load([]);

        $legacy = $container->getAlias(YopassAccessCheckerInterface::class);
        self::assertTrue($legacy->isDeprecated());
        self::assertSame([
            'package' => 'nowo-tech/yopass-bundle',
            'version' => '1.22.0',
            'message' => 'The "' . YopassAccessCheckerInterface::class . '" service alias is deprecated: type-hint "Nowo\YopassBundle\Security\YopassAccessPolicyInterface" instead. The interface and its alias are removed in 2.0.',
        ], $legacy->getDeprecation(YopassAccessCheckerInterface::class));
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
