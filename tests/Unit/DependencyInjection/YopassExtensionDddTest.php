<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\DependencyInjection\Compiler\AccessLogMappingPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

/**
 * 1.18.0: `user_mapping` and `access_log.repository` wiring; the defaults keep the 1.17 container.
 */
final class YopassExtensionDddTest extends TestCase
{
    public function testDefaultsKeepTheRelationMapping(): void
    {
        $container = $this->load([]);

        self::assertSame(CreatorMapping::RELATION, $container->getDefinition(DoctrineCreatorReferenceResolver::class)->getArgument('$userMapping'));
        self::assertSame(CreatorMapping::RELATION, $container->getDefinition(SecureShareMetadataListener::class)->getArgument('$userMapping'));
        self::assertFalse($container->hasParameter(AccessLogMappingPass::MAPPED_PARAMETER));
        self::assertTrue($container->getParameter(AccessLogMappingPass::ENABLED_PARAMETER));
    }

    public function testIdMappingReachesTheResolverAndTheListeners(): void
    {
        $orm = $this->load(['user_mapping' => 'id']);
        self::assertSame(CreatorMapping::ID, $orm->getDefinition(DoctrineCreatorReferenceResolver::class)->getArgument('$userMapping'));
        self::assertSame(CreatorMapping::ID, $orm->getDefinition(SecureShareMetadataListener::class)->getArgument('$userMapping'));

        $mongo = $this->load(['user_mapping' => 'id', 'database' => ['driver' => 'doctrine_mongodb', 'platform' => 'mongodb']]);
        self::assertSame(CreatorMapping::ID, $mongo->getDefinition(DoctrineCreatorReferenceResolver::class)->getArgument('$userMapping'));
        self::assertSame(CreatorMapping::ID, $mongo->getDefinition(SecureShareDocumentMetadataListener::class)->getArgument('$userMapping'));
    }

    public function testHostAccessLogRepositoryWorksWithEveryDriver(): void
    {
        foreach ([[], ['driver' => 'doctrine_mongodb', 'platform' => 'mongodb'], ['driver' => 'custom', 'platform' => 'mongodb', 'repository' => 'app.shares']] as $database) {
            $container = $this->load(['database' => $database, 'access_log' => ['repository' => 'app.access_log']]);

            self::assertSame('app.access_log', (string) $container->getAlias(ShareAccessLogRepositoryInterface::class));
            self::assertTrue($container->getParameter(AccessLogMappingPass::ENABLED_PARAMETER));
            self::assertFalse($container->getParameter(AccessLogMappingPass::MAPPED_PARAMETER));
        }
    }

    public function testHostAccessLogRepositoryIsIgnoredWhenTheLogIsDisabled(): void
    {
        $container = $this->load(['access_log' => ['enabled' => false, 'repository' => 'app.access_log']]);

        self::assertSame(NullShareAccessLogRepository::class, (string) $container->getAlias(ShareAccessLogRepositoryInterface::class));
        self::assertFalse($container->getParameter(AccessLogMappingPass::ENABLED_PARAMETER));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setDefinition('app.shares', new Definition(stdClass::class));
        $container->setDefinition('app.access_log', new Definition(stdClass::class));
        (new YopassExtension())->load([['user_class' => 'App\\Entity\\User'] + $config], $container);

        return $container;
    }
}
