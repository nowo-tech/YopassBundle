<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection;

use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Database\DatabaseDriver;
use Nowo\YopassBundle\DependencyInjection\Compiler\AccessLogMappingPass;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\EventListener\WasmCspListener;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\PublicEndpointRateLimiter;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\Reference;

use function array_column;
use function array_filter;
use function array_key_exists;
use function array_unique;
use function array_values;
use function in_array;
use function is_array;
use function is_string;
use function rtrim;
use function sprintf;
use function trim;

/**
 * Loads bundle configuration and registers services.
 */
final class YopassExtension extends Extension implements PrependExtensionInterface
{
    /** 1.x default layout (removed in 1.10: it loaded CSS frameworks from CDNs); mapped to null = the UiKit layout. */
    public const LEGACY_DEFAULT_LAYOUT = '@NowoYopassBundle/layout.html.twig';

    /**
     * Loads the bundle configuration and registers the bundle services.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config        = $this->processConfiguration($configuration, $configs);
        $webUi         = $this->resolveWebUi($config['web_ui']);

        $storageName = rtrim((string) $config['table_prefix'], '_') . '_secure_shares';
        $database    = $config['database'];
        $driver      = DatabaseDriver::resolveDriver($database['driver'], $database['platform']);
        $collection  = is_string($database['collection']) && $database['collection'] !== ''
            ? $database['collection']
            : $storageName;

        $container->setParameter('nowo_yopass.user_class', $config['user_class']);
        $container->setParameter('nowo_yopass.accepted_user_classes', $config['accepted_user_classes']);
        $container->setDefinition(CreatorClassPolicy::class, (new Definition(CreatorClassPolicy::class))
            ->setArgument('$userClass', $config['user_class'])
            ->setArgument('$acceptedUserClasses', $config['accepted_user_classes']));
        $container->setParameter('nowo_yopass.table_name', $storageName);
        $container->setParameter('nowo_yopass.collection_name', $collection);
        $container->setParameter('nowo_yopass.database', $database);
        $container->setParameter('nowo_yopass.max_ciphertext_bytes', $config['max_ciphertext_bytes']);
        $container->setParameter('nowo_yopass.max_secret_chars', $config['max_secret_chars']);
        // REQ-UI-003: web_ui.path_prefix is the canonical prefix (route_prefix is mapped onto it by Configuration).
        $container->setParameter('nowo_yopass.route_prefix', $webUi['path_prefix']);
        $container->setParameter('nowo_yopass.dashboard_route', $config['dashboard_route']);
        $container->setParameter('nowo_yopass.routes', $config['routes']);
        $container->setParameter('nowo_yopass.templates', $webUi['templates']);
        $container->setParameter('nowo_yopass.web_ui.enabled', $webUi['enabled']);
        $container->setParameter('nowo_yopass.web_ui.path_prefix', $webUi['path_prefix']);
        $container->setParameter('nowo_yopass.web_ui.create_mode', $webUi['create_mode']);
        $container->setParameter('nowo_yopass.web_ui.list_routes', $webUi['list_routes']);
        $container->setParameter('nowo_yopass.web_ui.icons', $webUi['icons']);
        $container->setParameter('nowo_yopass.manage_route_name', $config['routes']['manage']['name']);
        $container->setParameter('nowo_yopass.web_ui.layout_template', $webUi['layout_template']);
        $container->setParameter('nowo_yopass.web_ui.public_layout_template', $webUi['public_layout_template']);
        $container->setParameter('nowo_yopass.web_ui.templates', $webUi['templates']);
        $container->setParameter('nowo_yopass.web_ui.css_framework', $webUi['css_framework']);
        $container->setParameter('nowo_yopass.web_ui.icon_set', $webUi['icon_set']);
        $container->setParameter('nowo_yopass.firewall', $config['firewall']);
        $container->setParameter('nowo_yopass.public_firewall_paths', $config['public_firewall_paths']);
        $container->setParameter('nowo_yopass.security', $config['security']);
        $container->setParameter('nowo_yopass.shares', $config['shares']);
        $container->setParameter('nowo_yopass.sharing', $config['sharing']);
        $container->setParameter('nowo_yopass.expiration_options', $config['shares']['expiration_options']);
        $container->setParameter('nowo_yopass.access_log_enabled', $config['access_log']['enabled']);
        // CSP: route names of the pages that load yopass.js (libsodium needs 'wasm-unsafe-eval'); hosts may read this
        // parameter to scope the keyword. The opt-in listener only edits those routes.
        $container->setParameter('nowo_yopass.csp.wasm_routes', WasmCspListener::routeNames($config['routes']));
        $container->setParameter('nowo_yopass.csp.wasm_unsafe_eval', (bool) $config['csp']['wasm_unsafe_eval']);
        $container->setParameter('nowo_yopass.security_headers.enabled', (bool) $config['security_headers']['enabled']);
        $container->setParameter('nowo_yopass.route_names', array_values(array_unique(array_column($config['routes'], 'name'))));

        $publicRateLimit = $config['public_rate_limit'];
        $container->setParameter('nowo_yopass.public_rate_limit.enabled', $publicRateLimit['enabled']);
        $container->setParameter('nowo_yopass.public_rate_limit.limit', $publicRateLimit['enabled'] ? (int) $publicRateLimit['limit'] : 0);
        $container->setParameter('nowo_yopass.public_rate_limit.interval_seconds', $publicRateLimit['enabled'] ? (int) $publicRateLimit['interval_seconds'] : 0);
        // Extensions load into an isolated container (framework services are not visible here), so
        // cache.app / logger are optional references resolved at compile time; PublicRateLimitCachePass
        // warns when rate limiting is enabled but cache.app is missing.
        $container->register(PublicEndpointRateLimiter::class)
            ->setAutowired(false)
            ->setArguments([
                new Reference('cache.app', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                '%nowo_yopass.public_rate_limit.limit%',
                '%nowo_yopass.public_rate_limit.interval_seconds%',
                new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                // REQ-DI-001: framework clock when available (falls back to the system clock).
                new Reference(ClockInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]);

        $this->registerShareRepository($container, $driver, $database, $storageName, $collection, $config);
        $this->registerHostAccessLogRepository($container, $config['access_log']);

        $accessCheckerId = $config['security']['access_checker'] ?? null;
        if (!is_string($accessCheckerId) || $accessCheckerId === '') {
            $accessCheckerId = 'nowo_yopass.access_checker.default';
            $container->setDefinition($accessCheckerId, (new Definition(ConfigurableYopassAccessChecker::class))
                ->setAutowired(true)
                ->setArgument('$adminRoles', $config['security']['roles']['admin'])
                ->setArgument('$accessRoles', $config['security']['access_roles'])
                ->setArgument('$createRoles', $config['security']['roles']['create'])
                ->setArgument('$listRoles', $config['security']['roles']['list'])
                ->setArgument('$revokeRoles', $config['security']['roles']['revoke']));
        }

        $container->setAlias(YopassAccessCheckerInterface::class, $accessCheckerId);
        $container->setAlias(YopassAccessPolicyInterface::class, $accessCheckerId);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setDefinition(YopassAccessDecider::class, new Definition(YopassAccessDecider::class, [new Reference($accessCheckerId)]));

        // The configured checker may implement only YopassAccessPolicyInterface (1.19.0): wired by id, not by type.
        if ($container->hasDefinition(ShareManageController::class)) {
            $container->getDefinition(ShareManageController::class)
                ->setArgument('$accessChecker', new Reference($accessCheckerId))
                ->setArgument('$access', new Reference(YopassAccessDecider::class));
        }

        $this->registerFileHandler($container, $config['file_handler'] ?? null);
    }

    /**
     * Resolved web_ui settings (REQ-UI-003): the 1.x default layout value (removed bundle layout) maps to null (UiKit
     * layout), empty strings to null, and every per-page template id is present (null = bundle template).
     *
     * @param array<string, mixed> $webUi
     *
     * @return array{
     *     enabled: bool,
     *     path_prefix: string,
     *     create_mode: string,
     *     list_routes: list<string>,
     *     icons: array{create_tab_text: string|null, create_tab_file: string|null},
     *     layout_template: string|null,
     *     public_layout_template: string|null,
     *     templates: array{manage: string|null, created: string|null, public: string|null},
     *     css_framework: string|null,
     *     icon_set: string|null
     * }
     */
    private function resolveWebUi(array $webUi): array
    {
        $layout = is_string($webUi['layout_template'] ?? null) ? $webUi['layout_template'] : null;
        if ($layout === '' || $layout === self::LEGACY_DEFAULT_LAYOUT) {
            $layout = null;
        }

        $publicLayout = is_string($webUi['public_layout_template'] ?? null) && $webUi['public_layout_template'] !== ''
            ? $webUi['public_layout_template']
            : null;

        $icons = is_array($webUi['icons'] ?? null) ? $webUi['icons'] : [];

        $templates = [];
        foreach (['manage', 'created', 'public'] as $page) {
            $template         = $webUi['templates'][$page] ?? null;
            $templates[$page] = is_string($template) && $template !== '' ? $template : null;
        }

        return [
            'enabled'     => (bool) $webUi['enabled'],
            'create_mode' => ($webUi['create_mode'] ?? 'page') === 'dialog' ? 'dialog' : 'page',
            'list_routes' => array_values(array_filter((array) ($webUi['list_routes'] ?? []), is_string(...))),
            'icons'       => [
                'create_tab_text' => $this->iconName($icons['create_tab_text'] ?? null),
                'create_tab_file' => $this->iconName($icons['create_tab_file'] ?? null),
            ],
            'path_prefix'            => (string) ($webUi['path_prefix'] ?? ''),
            'layout_template'        => $layout,
            'public_layout_template' => $publicLayout,
            'templates'              => $templates,
            'css_framework'          => is_string($webUi['css_framework'] ?? null) ? $webUi['css_framework'] : null,
            'icon_set'               => is_string($webUi['icon_set'] ?? null) ? $webUi['icon_set'] : null,
        ];
    }

    /**
     * Icon name of a `web_ui.icons` entry: a non-empty string, else null (label only).
     */
    private function iconName(mixed $icon): ?string
    {
        return is_string($icon) && trim($icon) !== '' ? trim($icon) : null;
    }

    private function registerFileHandler(ContainerBuilder $container, mixed $fileHandlerId): void
    {
        $enabled = is_string($fileHandlerId) && $fileHandlerId !== '';
        $container->setParameter('nowo_yopass.file_shares_enabled', $enabled);
        $container->setParameter('nowo_yopass.file_handler', $enabled ? $fileHandlerId : null);

        $container->getDefinition(ShareManageController::class)
            ->setArgument('$fileSharesEnabled', $enabled);
    }

    /**
     * Returns the configuration alias ("nowo_yopass").
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends Form Kit, asset package, UI Kit and Doctrine mapping configuration.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependFormKitDefaults($container);
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', [
                'assets' => [
                    'packages' => [
                        'nowo_yopass' => [
                            'base_path' => '/bundles/yopass',
                        ],
                    ],
                ],
            ]);
        }

        $this->prependUiKitDefaults($container);

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
                    'mappings' => self::ormMappings($this->resolvedConfigFromContainer($container)),
                ],
            ]);
        }
    }

    /**
     * When FormKit is installed, register the yopass profile. Forms select it via #[FormKitConfig].
     */
    private function prependFormKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasProfile      = false;
        foreach ($container->getExtensionConfig('nowo_form_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            $profiles = $cfg['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists('yopass', $profiles)) {
                $hostHasProfile = true;
            }
        }

        $seed = [];

        if (!$hostHasCssFramework) {
            $seed['css_framework'] = 'bootstrap';
        }

        if (!$hostHasProfile) {
            // REQ-UI-003: no framework utility classes in bundle markup; the host form theme (FormKit css_framework,
            // or this profile, which the host may redefine) styles the fields. `nowo-ui-input` is the UiKit hook.
            $seed['profiles'] = [
                'yopass' => [
                    'alias'                           => 'yopass',
                    'translation_domain'              => 'NowoYopassBundle',
                    'convention_requires_translation' => true,
                    'defaults'                        => [
                        'attr'     => ['class' => 'nowo-ui-input'],
                        'row_attr' => [],
                    ],
                ],
            ];
        }

        if ($seed !== []) {
            $container->prependExtensionConfig('nowo_form_kit', $seed);
        }
    }

    /**
     * REQ-UI-003: the CSS stack and icon set are inherited from nowo_ui_kit. When the host still sets the deprecated
     * web_ui.css_framework / icon_set, copy them to nowo_ui_kit unless the host set those keys there.
     */
    private function prependUiKitDefaults(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_ui_kit')) {
            return;
        }

        $hostHasCssFramework = false;
        $hostHasIconSet      = false;
        foreach ($container->getExtensionConfig('nowo_ui_kit') as $cfg) {
            /** @var array<string, mixed> $cfg */
            if (array_key_exists('css_framework', $cfg)) {
                $hostHasCssFramework = true;
            }
            if (array_key_exists('icon_set', $cfg)) {
                $hostHasIconSet = true;
            }
        }

        $config   = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(Configuration::ALIAS));
        $webUi    = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];
        $defaults = [];

        $framework = $webUi['css_framework'] ?? null;
        if (!$hostHasCssFramework && is_string($framework)) {
            $defaults['css_framework'] = $framework === 'bootstrap' ? 'bootstrap5' : $framework;
        }
        $iconSet = $webUi['icon_set'] ?? null;
        if (!$hostHasIconSet && is_string($iconSet)) {
            $defaults['icon_set'] = $iconSet;
        }

        if ($defaults !== []) {
            $container->prependExtensionConfig('nowo_ui_kit', $defaults);
        }
    }

    /**
     * @param array<string, mixed> $database
     * @param array<string, mixed> $config
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
            // The host repository receives the users as given (no Doctrine manager to build references on).
            $this->registerCreatorReferenceResolver($container, $userClass, null, null, $config['user_mapping']);

            return;
        }

        if ($driver === DatabaseDriver::DOCTRINE_MONGODB) {
            $container->setParameter('nowo_yopass.access_log_enabled', false);
            $this->registerNullAccessLogRepository($container);
            $documentManagerName = (string) ($database['document_manager'] ?? 'default');
            $container->setDefinition(DoctrineMongoShareRepository::class, (new Definition(DoctrineMongoShareRepository::class))
                ->setAutowired(false)
                ->setArgument('$documentManager', new Reference(sprintf('doctrine_mongodb.odm.%s_document_manager', $documentManagerName)))
                ->setArgument('$registry', new Reference('doctrine_mongodb'))
                ->setArgument('$managerName', $documentManagerName)
                ->setArgument('$creators', new Reference(CreatorReferenceResolverInterface::class)));
            $this->registerCreatorReferenceResolver($container, $userClass, 'doctrine_mongodb', $documentManagerName, $config['user_mapping']);

            $container->setDefinition(SecureShareDocumentMetadataListener::class, (new Definition(SecureShareDocumentMetadataListener::class))
                ->setArgument('$collectionName', $collection)
                ->setArgument('$userClass', $userClass)
                ->setArgument('$userMapping', $config['user_mapping'])
                ->addTag('doctrine_mongodb.odm.event_listener', ['event' => 'loadClassMetadata']));

            $container->setAlias(ShareRepositoryInterface::class, DoctrineMongoShareRepository::class);

            return;
        }

        $this->registerOrmShareRepository($container, $database, $storageName, $userClass, $config);
        $container->getDefinition(DoctrineOrmShareRepository::class)->setArgument('$creators', new Reference(CreatorReferenceResolverInterface::class));
        $this->registerCreatorReferenceResolver($container, $userClass, 'doctrine', (string) $database['entity_manager'], $config['user_mapping']);
        if ($container->hasDefinition(YopassEntityManager::class)) {
            $container->getDefinition(DoctrineCreatorReferenceResolver::class)->setArgument('$ownManager', new Reference(YopassEntityManager::class));
        }
        $container->setAlias(ShareRepositoryInterface::class, DoctrineOrmShareRepository::class);
    }

    /**
     * Default {@see CreatorReferenceResolverInterface} (aliased: a host alias or service for the interface wins).
     */
    private function registerCreatorReferenceResolver(ContainerBuilder $container, string $userClass, ?string $registry, ?string $managerName, string $userMapping): void
    {
        $container->setDefinition(DoctrineCreatorReferenceResolver::class, (new Definition(DoctrineCreatorReferenceResolver::class))
            ->setArgument('$userClass', $userClass)
            ->setArgument('$registry', $registry !== null ? new Reference($registry) : null)
            ->setArgument('$managerName', $managerName)
            ->setArgument('$classes', new Reference(CreatorClassPolicy::class))
            ->setArgument('$userMapping', $userMapping));
        $container->setAlias(CreatorReferenceResolverInterface::class, DoctrineCreatorReferenceResolver::class);
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
            ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName)))
            ->setArgument('$registry', new Reference('doctrine'))
            ->setArgument('$managerName', $entityManagerName));

        // Rule 6 (1.19.0): the bundle's own entity manager, only when the bundle's ORM repository stores the shares
        // (with the custom driver the shares come from the host repository and its manager).
        $ownManager = null;
        if ((bool) ($database['private_entity_manager'] ?? true) && DatabaseDriver::resolveDriver((string) $database['driver'], (string) $database['platform']) === DatabaseDriver::DOCTRINE_ORM) {
            $container->setDefinition(YopassEntityManager::class, (new Definition(YopassEntityManager::class))
                ->setArgument('$registry', new Reference('doctrine'))
                ->setArgument('$entityManagerName', $entityManagerName)
                ->addTag('kernel.event_subscriber')
                ->addTag('kernel.reset', ['method' => 'reset']));
            $ownManager = new Reference(YopassEntityManager::class);
            $container->getDefinition(DoctrineOrmShareRepository::class)->setArgument('$ownManager', $ownManager);
        }

        $container->setDefinition(SecureShareMetadataListener::class, (new Definition(SecureShareMetadataListener::class))
            ->setArgument('$tableName', $storageName)
            ->setArgument('$userClass', $userClass)
            ->setArgument('$accessLogTableName', $accessLogEnabled ? $accessLogTable : null)
            ->setArgument('$userMapping', $config['user_mapping'])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        if ($accessLogEnabled) {
            $container->setDefinition(DoctrineOrmShareAccessLogRepository::class, (new Definition(DoctrineOrmShareAccessLogRepository::class))
                ->setAutowired(false)
                ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName)))
                ->setArgument('$registry', new Reference('doctrine'))
                ->setArgument('$managerName', $entityManagerName)
                ->setArgument('$ownManager', $ownManager));
            $container->setAlias(ShareAccessLogRepositoryInterface::class, DoctrineOrmShareAccessLogRepository::class);
        } else {
            $this->registerNullAccessLogRepository($container);
        }
    }

    /**
     * `access_log.repository` (1.18.0): the host's ShareAccessLogRepositoryInterface service, with every driver. The
     * history is then enabled whatever the backend, and the bundle no longer maps its ShareAccessLog entity.
     *
     * @param array{enabled: bool, repository: ?string} $accessLog
     */
    private function registerHostAccessLogRepository(ContainerBuilder $container, array $accessLog): void
    {
        $repositoryId = $accessLog['repository'];
        if (!$accessLog['enabled'] || !is_string($repositoryId) || $repositoryId === '') {
            return;
        }

        $container->setAlias(ShareAccessLogRepositoryInterface::class, $repositoryId);
        $container->setParameter(AccessLogMappingPass::ENABLED_PARAMETER, true);
        $container->setParameter(AccessLogMappingPass::MAPPED_PARAMETER, false);
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
        $config = $this->resolvedConfigFromContainer($container);
        if ($config === null) {
            return DatabaseDriver::DOCTRINE_ORM;
        }
        $database = $config['database'];

        return DatabaseDriver::resolveDriver($database['driver'], $database['platform']);
    }

    /**
     * The processed `nowo_yopass` configuration at prepend time (null when the host has none).
     *
     * @return array<string, mixed>|null
     */
    private function resolvedConfigFromContainer(ContainerBuilder $container): ?array
    {
        if (!$container->hasExtension('nowo_yopass')) {
            return null;
        }

        $configs = $container->getExtensionConfig('nowo_yopass');
        if ($configs === []) {
            return null;
        }

        return (new Processor())->processConfiguration(new Configuration(), $configs);
    }

    /**
     * Doctrine ORM mapping entries of the bundle entities: the attribute mapping (default), or with
     * `database.mapping: xml` (1.19.0) the XML files, the access log only when the bundle maps it.
     *
     * @param array<string, mixed>|null $config
     *
     * @return array<string, array<string, mixed>>
     */
    public static function ormMappings(?array $config): array
    {
        $database = $config['database'] ?? [];
        if (!is_array($database) || ($database['mapping'] ?? 'attribute') !== 'xml') {
            return ['YopassBundle' => ['type' => 'attribute', 'is_bundle' => true]];
        }

        $accessLog  = is_array($config['access_log'] ?? null) ? $config['access_log'] : [];
        $hostLog    = is_string($accessLog['repository'] ?? null) && $accessLog['repository'] !== '';
        $platform   = (string) ($database['platform'] ?? DatabaseDriver::PLATFORM_POSTGRESQL);
        $driver     = DatabaseDriver::resolveDriver((string) ($database['driver'] ?? DatabaseDriver::DOCTRINE_ORM), $platform);
        $relational = in_array($platform, DatabaseDriver::relationalPlatforms(), true);
        $mapsLog    = ($accessLog['enabled'] ?? true) === true && !$hostLog && ($driver === DatabaseDriver::DOCTRINE_ORM || $relational);

        // One directory: DoctrineBundle keys the mapping directories by namespace prefix (shares_only holds the same
        // SecureShare.orm.xml without the access log, so no empty access log table is created).
        return ['YopassBundle' => [
            'type'      => 'xml',
            'is_bundle' => false,
            'dir'       => __DIR__ . '/../Resources/config/doctrine/' . ($mapsLog ? 'shares_and_access_log' : 'shares_only'),
            'prefix'    => 'Nowo\\YopassBundle\\Entity',
        ]];
    }
}
