<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\DependencyInjection\Compiler\FileHandlerPass;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class YopassExtensionTest extends TestCase
{
    private YopassExtension $extension;

    private ContainerBuilder $container;

    protected function setUp(): void
    {
        $this->extension = new YopassExtension();
        $this->container = new ContainerBuilder();
    }

    public function testGetAlias(): void
    {
        self::assertSame('nowo_yopass', $this->extension->getAlias());
    }

    public function testLoadSetsParametersAndDefaultAccessChecker(): void
    {
        $this->extension->load([['user_class' => 'App\\Entity\\User']], $this->container);

        self::assertSame('App\\Entity\\User', $this->container->getParameter('nowo_yopass.user_class'));
        self::assertSame('yopass_secure_shares', $this->container->getParameter('nowo_yopass.table_name'));
        self::assertSame('1h', $this->container->getParameter('nowo_yopass.shares')['default_expiration']);
        self::assertTrue($this->container->hasDefinition('nowo_yopass.access_checker.default'));
        self::assertSame(
            ConfigurableYopassAccessChecker::class,
            $this->container->getDefinition('nowo_yopass.access_checker.default')->getClass(),
        );
        self::assertSame(
            'nowo_yopass.access_checker.default',
            (string) $this->container->getAlias(YopassAccessCheckerInterface::class),
        );
        self::assertTrue($this->container->hasDefinition(SecureShareMetadataListener::class));
        self::assertTrue($this->container->hasDefinition(DoctrineOrmShareRepository::class));
        self::assertTrue($this->container->hasDefinition(DoctrineOrmShareAccessLogRepository::class));
        self::assertSame(
            DoctrineOrmShareAccessLogRepository::class,
            (string) $this->container->getAlias(ShareAccessLogRepositoryInterface::class),
        );
        self::assertTrue($this->container->getParameter('nowo_yopass.access_log_enabled'));
        self::assertSame('auto', $this->container->getParameter('nowo_yopass.sharing')['default_encryption']);
        self::assertSame(
            DoctrineOrmShareRepository::class,
            (string) $this->container->getAlias(ShareRepositoryInterface::class),
        );
        self::assertTrue($this->container->hasDefinition(\Nowo\YopassBundle\Controller\ShareManageController::class));
    }

    public function testLoadUsesCustomTablePrefix(): void
    {
        $this->extension->load([[
            'user_class'   => 'App\\Entity\\User',
            'table_prefix' => 'vault_',
        ]], $this->container);

        self::assertSame('vault_secure_shares', $this->container->getParameter('nowo_yopass.table_name'));
    }

    public function testLoadRegistersFileHandlerWhenConfigured(): void
    {
        $this->container->setDefinition('app.yopass.file_handler', new \Symfony\Component\DependencyInjection\Definition(stdClass::class));

        $this->extension->load([[
            'user_class'    => 'App\\Entity\\User',
            'file_handler'  => 'app.yopass.file_handler',
        ]], $this->container);

        self::assertTrue($this->container->getParameter('nowo_yopass.file_shares_enabled'));
        self::assertSame('app.yopass.file_handler', $this->container->getParameter('nowo_yopass.file_handler'));

        $this->container->addCompilerPass(new FileHandlerPass());
        (new FileHandlerPass())->process($this->container);

        self::assertSame(
            'app.yopass.file_handler',
            (string) $this->container->getAlias(\Nowo\YopassBundle\Service\ShareFileHandlerInterface::class),
        );
    }

    public function testLoadUsesCustomAccessCheckerService(): void
    {
        $this->container->setDefinition('app.yopass.access', new \Symfony\Component\DependencyInjection\Definition(stdClass::class));

        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'security'   => ['access_checker' => 'app.yopass.access'],
        ]], $this->container);

        self::assertFalse($this->container->hasDefinition('nowo_yopass.access_checker.default'));
        self::assertSame('app.yopass.access', (string) $this->container->getAlias(YopassAccessCheckerInterface::class));
    }

    public function testLoadUsesCustomRepositoryForCustomDriver(): void
    {
        $this->container->setDefinition('app.yopass.repository', new \Symfony\Component\DependencyInjection\Definition(stdClass::class));

        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'database'   => [
                'driver'     => 'custom',
                'repository' => 'app.yopass.repository',
            ],
        ]], $this->container);

        self::assertSame('app.yopass.repository', (string) $this->container->getAlias(ShareRepositoryInterface::class));
        self::assertTrue($this->container->hasDefinition(DoctrineOrmShareRepository::class));
    }

    public function testLoadFailsWhenCustomDriverHasNoRepository(): void
    {
        $this->expectException(\Symfony\Component\Config\Definition\Exception\InvalidConfigurationException::class);
        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'database'   => ['driver' => 'custom'],
        ]], $this->container);
    }

    public function testLoadUsesCustomCollectionName(): void
    {
        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'database'   => [
                'driver'     => 'doctrine_mongodb',
                'platform'   => 'mongodb',
                'collection' => 'custom_shares',
            ],
        ]], $this->container);

        self::assertSame('custom_shares', $this->container->getParameter('nowo_yopass.collection_name'));
    }

    public function testPrependRegistersMongoMappingsWhenPlatformIsMongo(): void
    {
        $this->container->registerExtension(new \Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension());
        $this->container->registerExtension(new \Doctrine\Bundle\MongoDBBundle\DependencyInjection\DoctrineMongoDBExtension());
        $this->container->registerExtension($this->extension);
        $this->container->loadFromExtension('nowo_yopass', [
            'user_class' => 'App\\Entity\\User',
            'database'   => [
                'driver'   => 'doctrine_mongodb',
                'platform' => 'mongodb',
            ],
        ]);

        $this->extension->prepend($this->container);

        $mongoConfigs = $this->container->getExtensionConfig('doctrine_mongodb');
        self::assertNotEmpty($mongoConfigs);
        self::assertArrayHasKey('YopassBundle', $mongoConfigs[0]['document_managers']['default']['mappings']);
    }

    public function testLoadRegistersMongoRepositoryWhenPlatformIsMongo(): void
    {
        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'database'   => [
                'driver'           => 'doctrine_mongodb',
                'platform'         => 'mongodb',
                'document_manager' => 'default',
            ],
        ]], $this->container);

        self::assertTrue($this->container->hasDefinition(\Nowo\YopassBundle\Repository\DoctrineMongoShareRepository::class));
        self::assertSame(
            \Nowo\YopassBundle\Repository\DoctrineMongoShareRepository::class,
            (string) $this->container->getAlias(ShareRepositoryInterface::class),
        );
        self::assertFalse($this->container->hasDefinition(SecureShareMetadataListener::class));
        self::assertFalse($this->container->getParameter('nowo_yopass.access_log_enabled'));
        self::assertSame(
            NullShareAccessLogRepository::class,
            (string) $this->container->getAlias(ShareAccessLogRepositoryInterface::class),
        );
    }

    public function testLoadDisablesAccessLogWhenConfigured(): void
    {
        $this->extension->load([[
            'user_class' => 'App\\Entity\\User',
            'access_log' => ['enabled' => false],
        ]], $this->container);

        self::assertFalse($this->container->getParameter('nowo_yopass.access_log_enabled'));
        self::assertSame(
            NullShareAccessLogRepository::class,
            (string) $this->container->getAlias(ShareAccessLogRepositoryInterface::class),
        );
        self::assertFalse($this->container->hasDefinition(DoctrineOrmShareAccessLogRepository::class));
    }

    public function testPrependDefaultsToOrmWithoutBundleConfig(): void
    {
        $this->container->registerExtension(new \Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension());
        $this->container->registerExtension(new \Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension());
        $this->container->registerExtension($this->extension);

        $this->extension->prepend($this->container);

        $doctrineConfigs = $this->container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('YopassBundle', $doctrineConfigs[0]['orm']['mappings']);
    }

    public function testPrependRegistersFrameworkAssetsAndDoctrineMappings(): void
    {
        $this->container->registerExtension(new \Symfony\Bundle\FrameworkBundle\DependencyInjection\FrameworkExtension());
        $this->container->registerExtension(new \Doctrine\Bundle\DoctrineBundle\DependencyInjection\DoctrineExtension());
        $this->extension->prepend($this->container);

        $configs = $this->container->getExtensionConfig('framework');
        self::assertNotEmpty($configs);
        self::assertArrayHasKey('assets', $configs[0]);
        self::assertArrayHasKey('nowo_yopass', $configs[0]['assets']['packages']);

        $doctrineConfigs = $this->container->getExtensionConfig('doctrine');
        self::assertNotEmpty($doctrineConfigs);
        self::assertArrayHasKey('YopassBundle', $doctrineConfigs[0]['orm']['mappings']);
    }

    public function testPrependNoOpWithoutFrameworkExtension(): void
    {
        $this->extension->prepend($this->container);
        self::assertSame([], $this->container->getExtensions());
    }
}
