<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection;

use Nowo\YopassBundle\Database\DatabaseDriver;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function in_array;
use function is_string;
use function rtrim;
use function sprintf;

/**
 * Loads bundle configuration and registers services.
 */
final class YopassExtension extends Extension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);

        $storageName = rtrim((string) $config['table_prefix'], '_') . '_secure_shares';
        $database    = $config['database'];
        $driver      = DatabaseDriver::resolveDriver($database['driver'], $database['platform']);
        $collection  = is_string($database['collection']) && $database['collection'] !== ''
            ? $database['collection']
            : $storageName;

        $container->setParameter('nowo_yopass.user_class', $config['user_class']);
        $container->setParameter('nowo_yopass.table_name', $storageName);
        $container->setParameter('nowo_yopass.collection_name', $collection);
        $container->setParameter('nowo_yopass.database', $database);
        $container->setParameter('nowo_yopass.max_ciphertext_bytes', $config['max_ciphertext_bytes']);
        $container->setParameter('nowo_yopass.max_secret_chars', $config['max_secret_chars']);
        $container->setParameter('nowo_yopass.route_prefix', $config['route_prefix']);
        $container->setParameter('nowo_yopass.dashboard_route', $config['dashboard_route']);
        $container->setParameter('nowo_yopass.routes', $config['routes']);
        $container->setParameter('nowo_yopass.templates', $config['templates']);
        $container->setParameter('nowo_yopass.firewall', $config['firewall']);
        $container->setParameter('nowo_yopass.public_firewall_paths', $config['public_firewall_paths']);
        $container->setParameter('nowo_yopass.security', $config['security']);
        $container->setParameter('nowo_yopass.shares', $config['shares']);
        $container->setParameter('nowo_yopass.sharing', $config['sharing']);
        $container->setParameter('nowo_yopass.expiration_options', $config['shares']['expiration_options']);
        $container->setParameter('nowo_yopass.access_log_enabled', $config['access_log']['enabled']);

        $publicRateLimit = $config['public_rate_limit'];
        $container->setParameter('nowo_yopass.public_rate_limit.enabled', $publicRateLimit['enabled']);
        $container->register(\Nowo\YopassBundle\Security\PublicEndpointRateLimiter::class)
            ->setAutowired(false)
            ->setArguments([
                $container->has('cache.app') ? new Reference('cache.app') : null,
                $publicRateLimit['enabled'] ? (int) $publicRateLimit['limit'] : 0,
                $publicRateLimit['enabled'] ? (int) $publicRateLimit['interval_seconds'] : 0,
            ]);

        $this->registerShareRepository($container, $driver, $database, $storageName, $collection, $config);

        $accessCheckerId = $config['security']['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_yopass.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableYopassAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$adminRoles', $config['security']['admin_roles'])
                ->setArgument('$accessRoles', $config['security']['access_roles'])
                ->setArgument('$createRoles', $config['security']['create_roles'])
                ->setArgument('$listRoles', $config['security']['list_roles'])
                ->setArgument('$revokeRoles', $config['security']['revoke_roles']));
        }

        $container->setAlias(YopassAccessCheckerInterface::class, $accessCheckerId);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $this->registerFileHandler($container, $config['file_handler'] ?? null);
    }

    private function registerFileHandler(ContainerBuilder $container, mixed $fileHandlerId): void
    {
        $enabled = is_string($fileHandlerId) && $fileHandlerId !== '';
        $container->setParameter('nowo_yopass.file_shares_enabled', $enabled);
        $container->setParameter('nowo_yopass.file_handler', $enabled ? $fileHandlerId : null);

        $container->getDefinition(\Nowo\YopassBundle\Controller\ShareManageController::class)
            ->setArgument('$fileSharesEnabled', $enabled);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('framework')) {
            return;
        }

        $container->prependExtensionConfig('framework', [
            'assets' => [
                'packages' => [
                    'nowo_yopass' => [
                        'base_path' => '/bundles/yopass',
                    ],
                ],
            ],
        ]);

        $driver = $this->resolveDriverFromContainerConfig($container);

        if ($driver === DatabaseDriver::DOCTRINE_MONGODB && $container->hasExtension('doctrine_mongodb')) {
            $container->prependExtensionConfig('doctrine_mongodb', [
                'document_managers' => [
                    'default' => [
                        'mappings' => [
                            'YopassBundle' => [
                                'type'      => 'attribute',
                                'is_bundle' => true,
                            ],
                        ],
                    ],
                ],
            ]);

            return;
        }

        if ($container->hasExtension('doctrine')) {
            $container->prependExtensionConfig('doctrine', [
                'orm' => [
                    'mappings' => [
                        'YopassBundle' => [
                            'type'      => 'attribute',
                            'is_bundle' => true,
                        ],
                    ],
                ],
            ]);
        }
    }

    /**
     * @param array<string, mixed> $database
     */
    private function registerShareRepository(
        ContainerBuilder $container,
        string $driver,
        array $database,
        string $storageName,
        string $collection,
        array $config,
    ): void {
        $userClass = $config['user_class'];

        if ($driver === DatabaseDriver::CUSTOM) {
            $repositoryId = $database['repository'] ?? null;
            if (!is_string($repositoryId) || $repositoryId === '') {
                throw new InvalidConfigurationException('The option "nowo_yopass.database.repository" is required when "database.driver" is "custom".');
            }

            if ($this->isRelationalPlatform((string) $database['platform'])) {
                $this->registerOrmShareRepository($container, $database, $storageName, $userClass, $config);
            } else {
                $container->setParameter('nowo_yopass.access_log_enabled', false);
                $this->registerNullAccessLogRepository($container);
            }

            $container->setAlias(ShareRepositoryInterface::class, $repositoryId);

            return;
        }

        if ($driver === DatabaseDriver::DOCTRINE_MONGODB) {
            $container->setParameter('nowo_yopass.access_log_enabled', false);
            $this->registerNullAccessLogRepository($container);
            $documentManagerName = (string) $database['document_manager'];
            $container->setDefinition(DoctrineMongoShareRepository::class, (new Definition(DoctrineMongoShareRepository::class))
                ->setAutowired(false)
                ->setArgument('$documentManager', new Reference(sprintf('doctrine_mongodb.odm.%s_document_manager', $documentManagerName))));

            $container->setDefinition(SecureShareDocumentMetadataListener::class, (new Definition(SecureShareDocumentMetadataListener::class))
                ->setArgument('$collectionName', $collection)
                ->setArgument('$userClass', $userClass)
                ->addTag('doctrine_mongodb.odm.event_listener', ['event' => 'loadClassMetadata']));

            $container->setAlias(ShareRepositoryInterface::class, DoctrineMongoShareRepository::class);

            return;
        }

        $this->registerOrmShareRepository($container, $database, $storageName, $userClass, $config);
        $container->setAlias(ShareRepositoryInterface::class, DoctrineOrmShareRepository::class);
    }

    /**
     * @param array<string, mixed> $database
     * @param array<string, mixed> $config
     */
    private function registerOrmShareRepository(
        ContainerBuilder $container,
        array $database,
        string $storageName,
        string $userClass,
        array $config,
    ): void {
        $entityManagerName = (string) $database['entity_manager'];
        $accessLogEnabled  = (bool) $config['access_log']['enabled'];
        $accessLogTable    = rtrim((string) $config['table_prefix'], '_') . '_share_access_logs';

        $container->setDefinition(DoctrineOrmShareRepository::class, (new Definition(DoctrineOrmShareRepository::class))
            ->setAutowired(false)
            ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName))));

        $container->setDefinition(SecureShareMetadataListener::class, (new Definition(SecureShareMetadataListener::class))
            ->setArgument('$tableName', $storageName)
            ->setArgument('$userClass', $userClass)
            ->setArgument('$accessLogTableName', $accessLogEnabled ? $accessLogTable : null)
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        if ($accessLogEnabled) {
            $container->setDefinition(DoctrineOrmShareAccessLogRepository::class, (new Definition(DoctrineOrmShareAccessLogRepository::class))
                ->setAutowired(false)
                ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName))));
            $container->setAlias(ShareAccessLogRepositoryInterface::class, DoctrineOrmShareAccessLogRepository::class);
        } else {
            $this->registerNullAccessLogRepository($container);
        }
    }

    private function registerNullAccessLogRepository(ContainerBuilder $container): void
    {
        $container->setDefinition(NullShareAccessLogRepository::class, new Definition(NullShareAccessLogRepository::class));
        $container->setAlias(ShareAccessLogRepositoryInterface::class, NullShareAccessLogRepository::class);
    }

    private function isRelationalPlatform(string $platform): bool
    {
        return in_array($platform, DatabaseDriver::relationalPlatforms(), true);
    }

    private function resolveDriverFromContainerConfig(ContainerBuilder $container): string
    {
        if (!$container->hasExtension('nowo_yopass')) {
            return DatabaseDriver::DOCTRINE_ORM;
        }

        $configs = $container->getExtensionConfig('nowo_yopass');
        if ($configs === []) {
            return DatabaseDriver::DOCTRINE_ORM;
        }

        $config   = (new Processor())->processConfiguration(new Configuration(), $configs);
        $database = $config['database'];

        return DatabaseDriver::resolveDriver($database['driver'], $database['platform']);
    }
}
