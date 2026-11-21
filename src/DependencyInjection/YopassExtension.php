<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection;

use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\TransactionDoctrine\WriteUnit\Dbal\CommitTrackingMiddleware;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorReferenceResolverInterface;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Database\DatabaseDriver;
use Nowo\YopassBundle\DependencyInjection\Compiler\AccessLogMappingPass;
use Nowo\YopassBundle\DependencyInjection\Compiler\PrivateEntityManagerPass;
use Nowo\YopassBundle\Doctrine\HostCommitEventRelay;
use Nowo\YopassBundle\Doctrine\HostCreatorReference;
use Nowo\YopassBundle\Doctrine\PrivateEntityManagerMiddleware;
use Nowo\YopassBundle\Doctrine\SecureShareDocumentMetadataListener;
use Nowo\YopassBundle\Doctrine\SecureShareMetadataListener;
use Nowo\YopassBundle\Doctrine\ShareWriteUnit;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Doctrine\YopassEntityManagerBoundarySubscriber;
use Nowo\YopassBundle\EventListener\WasmCspListener;
use Nowo\YopassBundle\EventListener\YopassConflictListener;
use Nowo\YopassBundle\Repository\DoctrineMongoShareRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareAccessLogRepository;
use Nowo\YopassBundle\Repository\DoctrineOrmShareRepository;
use Nowo\YopassBundle\Repository\NullShareAccessLogRepository;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\PublicEndpointRateLimiter;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use Nowo\YopassBundle\Service\ShareCommands;
use Nowo\YopassBundle\Service\ShareConsumedNotifier;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRevoker;
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
use Symfony\Component\HttpKernel\KernelEvents;

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

    /** 1.21.0: real commits counted by {@see self::COMMIT_TRACKING_MIDDLEWARE}, read by the conflict answer. */
    public const COMMIT_LEDGER_SERVICE = 'nowo_yopass.commit_ledger';

    /** 1.21.0: transaction-doctrine's commit-tracking DBAL middleware (scoped by {@see YopassCommitTrackingPass}). */
    public const COMMIT_TRACKING_MIDDLEWARE = 'nowo_yopass.commit_tracking_middleware';

    /** 1.21.0: container parameter holding `conflict_response.commit_tracking` (bundle, all or off). */
    public const COMMIT_TRACKING_PARAMETER = 'nowo_yopass.conflict_response.commit_tracking';

    /** 1.21.0: container parameter holding `database.entity_manager` for {@see YopassCommitTrackingPass}. */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_yopass.conflict_response.entity_manager';

    /**
     * 1.22.0: id of transaction-doctrine's host-flush probe in ORM host mode. No service has this id since 2.0 (the host
     * mode is removed).
     *
     * @deprecated since 2.0, no service is registered under this id; removed in 3.0
     */
    public const HOST_FLUSH_PROBE = 'nowo_yopass.host_flush_probe';

    /**
     * 1.22.0: id of the boundary subscriber of {@see self::HOST_FLUSH_PROBE}. No service has this id since 2.0.
     *
     * @deprecated since 2.0, no service is registered under this id; removed in 3.0
     */
    public const HOST_FLUSH_PROBE_BOUNDARIES = 'nowo_yopass.host_flush_probe.boundaries';

    /** 2.1.0: the boundary subscriber that releases or drops the held share events (Doctrine ORM driver). */
    public const HOST_COMMIT_EVENTS_BOUNDARIES = 'nowo_yopass.host_commit_events.boundaries';

    /** 1.22.0: the boundary subscriber that drops the private document manager (MongoDB ODM driver). */
    public const PRIVATE_DOCUMENT_MANAGER_BOUNDARIES = 'nowo_yopass.private_document_manager.boundaries';

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
        $this->registerConflictAnswer($container, $config['conflict_response'], (string) $database['entity_manager']);

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

        // 2.0: the configured checker must implement YopassAccessPolicyInterface (AccessCheckerAliasPass fails the build
        // otherwise).
        $container->setAlias(YopassAccessPolicyInterface::class, $accessCheckerId);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');

        $container->setDefinition(YopassAccessDecider::class, new Definition(YopassAccessDecider::class, [new Reference($accessCheckerId)]));

        // The configured checker is wired by id, not by type.
        if ($container->hasDefinition(ShareManageController::class)) {
            $container->getDefinition(ShareManageController::class)
                ->setArgument('$accessChecker', new Reference($accessCheckerId))
                ->setArgument('$access', new Reference(YopassAccessDecider::class));
        }

        $this->registerFileHandler($container, $config['file_handler'] ?? null);
        $this->registerHostCommitEvents($container, $driver, (bool) $config['events']['after_host_commit']);
    }

    /**
     * 2.1.0 (REQ-ARCH-001 rule 7): with the Doctrine ORM driver and doctrine/dbal 4, the write services dispatch their
     * "after" events through {@see HostCommitEventRelay}, which holds them while the application's transaction is open
     * and releases them after its commit (dropped on rollback). Otherwise nothing is registered and every event is
     * dispatched at once, as in 2.0.
     */
    private function registerHostCommitEvents(ContainerBuilder $container, string $driver, bool $afterHostCommit): void
    {
        if (!$afterHostCommit || $driver !== DatabaseDriver::DOCTRINE_ORM || !$container->hasDefinition(PrivateEntityManagerMiddleware::class)) {
            return;
        }

        $relay = new Reference(HostCommitEventRelay::class);
        $container->setDefinition(HostCommitEventRelay::class, (new Definition(HostCommitEventRelay::class))
            ->setArgument('$dispatcher', new Reference('event_dispatcher'))
            ->setArgument('$ownManager', new Reference(YopassEntityManager::class))
            ->setArgument('$enabled', true)
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber')
            ->addTag('kernel.reset', ['method' => 'reset']));
        // Same outermost request / message / command boundaries as the private entity manager.
        $container->setDefinition(self::HOST_COMMIT_EVENTS_BOUNDARIES, (new Definition(YopassEntityManagerBoundarySubscriber::class))
            ->setArgument('$entityManager', $relay)
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber'));
        $container->getDefinition(PrivateEntityManagerMiddleware::class)->setArgument('$events', $relay);

        foreach ([ShareCreator::class, ShareDeleter::class, ShareRevoker::class, ShareExtender::class, ShareRetentionPurger::class, ShareConsumedNotifier::class, ShareCommands::class] as $service) {
            if ($container->hasDefinition($service)) {
                $container->getDefinition($service)->setArgument('$eventDispatcher', $relay);
            }
        }
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
     *     templates: array{manage: string|null, created: string|null, public: string|null}
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
     * Prepends Form Kit, asset package and Doctrine mapping configuration.
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
                ->setArgument('$creators', new Reference(CreatorReferenceResolverInterface::class)));
            $this->registerCreatorReferenceResolver($container, $userClass, 'doctrine_mongodb', $documentManagerName, $config['user_mapping']);
            $this->registerPrivateDocumentManager($container, $documentManagerName);

            $container->setDefinition(SecureShareDocumentMetadataListener::class, (new Definition(SecureShareDocumentMetadataListener::class))
                ->setArgument('$collectionName', $collection)
                ->setArgument('$userClass', $userClass)
                ->setArgument('$userMapping', $config['user_mapping'])
                ->addTag('doctrine_mongodb.odm.event_listener', ['event' => 'loadClassMetadata']));

            $container->setAlias(ShareRepositoryInterface::class, DoctrineMongoShareRepository::class);

            return;
        }

        $this->registerOrmShareRepository($container, $database, $storageName, $userClass, $config);
        $ownManager = new Reference(YopassEntityManager::class);
        $container->getDefinition(DoctrineOrmShareRepository::class)->setArgument('$creators', new Reference(CreatorReferenceResolverInterface::class));
        $this->registerCreatorReferenceResolver($container, $userClass, 'doctrine', (string) $database['entity_manager'], $config['user_mapping']);
        $container->getDefinition(DoctrineCreatorReferenceResolver::class)->setArgument('$ownManager', $ownManager);
        $container->setAlias(ShareRepositoryInterface::class, DoctrineOrmShareRepository::class);

        // 1.21.0 (REQ-RUNTIME-003 rule 1): one transaction per write unit (consume + access log, extend) on the bundle's
        // own entity manager, injected into ShareRetriever and ShareExtender by autowiring (absent with the MongoDB and
        // custom drivers).
        $container->setDefinition(ShareWriteUnit::class, new Definition(ShareWriteUnit::class, [$ownManager]));
    }

    /**
     * Rule 6 on the MongoDB ODM driver (1.22.0 opt-in, the only mode since 2.0): the shares are read and written through
     * the bundle's own document manager ({@see YopassDocumentManager}), dropped at the outermost request / message /
     * command boundaries and at `kernel.reset`, so the application's document manager is never flushed.
     */
    private function registerPrivateDocumentManager(ContainerBuilder $container, string $documentManagerName): void
    {
        $container->setDefinition(YopassDocumentManager::class, (new Definition(YopassDocumentManager::class))
            ->setArgument('$registry', new Reference('doctrine_mongodb'))
            ->setArgument('$documentManagerName', $documentManagerName)
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.reset', ['method' => 'reset']));
        $container->setDefinition(self::PRIVATE_DOCUMENT_MANAGER_BOUNDARIES, (new Definition(YopassEntityManagerBoundarySubscriber::class))
            ->setArgument('$entityManager', new Reference(YopassDocumentManager::class))
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber'));

        $ownManager = new Reference(YopassDocumentManager::class);
        $container->getDefinition(DoctrineMongoShareRepository::class)->setArgument('$ownManager', $ownManager);
        $container->getDefinition(DoctrineCreatorReferenceResolver::class)->setArgument('$ownManager', $ownManager);
        $container->setDefinition(HostCreatorReference::class, new Definition(HostCreatorReference::class, [$ownManager]));
    }

    /**
     * 1.21.0 (REQ-RUNTIME-003 rule 6): 409 (or 503) + CONFLICT_001 / CONFLICT_002 instead of a 500 for lock conflicts on
     * the bundle's routes ({@see YopassConflictListener}). Unset options follow transaction-doctrine's policy service
     * when its bundle is registered.
     *
     * Commit tracking (`conflict_response.commit_tracking`), only when the bundle stores rows through Doctrine ORM:
     * `bundle` (default) and `all` register transaction-doctrine's commit-tracking middleware;
     * {@see YopassCommitTrackingPass} scopes it to the connection of `database.entity_manager` (`bundle`), or drops it and
     * reuses transaction-doctrine's own ledger when that bundle's `commit_detection` is on. `off` registers neither.
     *
     * @param array{status: int|null, retry_after: int|null, commit_tracking: string} $conflictResponse
     */
    private function registerConflictAnswer(ContainerBuilder $container, array $conflictResponse, string $entityManagerName): void
    {
        $container->setParameter(self::COMMIT_TRACKING_PARAMETER, $conflictResponse['commit_tracking']);
        $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $entityManagerName);
        $tracking = $conflictResponse['commit_tracking'] !== 'off' && $container->hasDefinition(DoctrineOrmShareRepository::class);
        if ($tracking) {
            $container->setDefinition(self::COMMIT_LEDGER_SERVICE, new Definition(CommitLedger::class));
            $container->setDefinition(self::COMMIT_TRACKING_MIDDLEWARE, (new Definition(CommitTrackingMiddleware::class, [new Reference(self::COMMIT_LEDGER_SERVICE)]))
                ->addTag('doctrine.middleware'));
        }

        $policy = $conflictResponse['status'] === null && $conflictResponse['retry_after'] === null
            ? new Reference(ConflictResponsePolicy::class, ContainerInterface::NULL_ON_INVALID_REFERENCE)
            : new Definition(ConflictResponsePolicy::class, [
                $conflictResponse['status'] ?? ConflictResponsePolicy::STATUS_CONFLICT,
                $conflictResponse['retry_after'] ?? ConflictResponsePolicy::DEFAULT_RETRY_AFTER,
            ]);

        $container->setDefinition(YopassConflictListener::class, (new Definition(YopassConflictListener::class, [
            $policy,
            $tracking ? new Reference(self::COMMIT_LEDGER_SERVICE) : null,
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))
            ->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onRequest', 'priority' => YopassConflictListener::REQUEST_PRIORITY])
            ->addTag('kernel.event_listener', ['event' => KernelEvents::EXCEPTION, 'method' => 'onException', 'priority' => YopassConflictListener::PRIORITY]));
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
            ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName))));

        // Rule 6 (1.19.0; the only mode since 2.0): the bundle's own entity manager on `database.entity_manager`. It
        // stores the shares with the ORM driver and, with the custom driver on a relational platform, the access log
        // (same connection and table); the application's manager is never flushed.
        $container->setDefinition(YopassEntityManager::class, (new Definition(YopassEntityManager::class))
            ->setArgument('$registry', new Reference('doctrine'))
            ->setArgument('$entityManagerName', $entityManagerName)
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('kernel.reset', ['method' => 'reset']));
        // 1.20.0 (REQ-RUNTIME-002): dropped at the OUTERMOST request / message / command boundaries only.
        $container->setDefinition(YopassEntityManagerBoundarySubscriber::class, (new Definition(YopassEntityManagerBoundarySubscriber::class))
            ->setArgument('$entityManager', new Reference(YopassEntityManager::class))
            ->setArgument('$requestStack', new Reference('request_stack', ContainerInterface::IGNORE_ON_INVALID_REFERENCE))
            ->addTag('kernel.event_subscriber'));
        // 1.20.0: replaced after a rollback on its connection (doctrine/dbal 4 only).
        if (PrivateEntityManagerPass::supportsRollbackMiddleware()) {
            $container->setParameter(PrivateEntityManagerPass::MANAGER_PARAMETER, $entityManagerName);
            $container->setDefinition(PrivateEntityManagerMiddleware::class, (new Definition(PrivateEntityManagerMiddleware::class))
                ->setArgument('$entityManager', new Reference(YopassEntityManager::class))
                ->addTag('doctrine.middleware'));
        }
        $ownManager = new Reference(YopassEntityManager::class);
        $container->getDefinition(DoctrineOrmShareRepository::class)->setArgument('$ownManager', $ownManager);
        // Events hand the application the creator in its own manager (1.19.0, audit MO-Y-L1). 2.0.1: also with the custom
        // driver on a relational platform, whose repository may decorate the bundle's one on the private manager.
        $container->setDefinition(HostCreatorReference::class, new Definition(HostCreatorReference::class, [$ownManager]));

        $container->setDefinition(SecureShareMetadataListener::class, (new Definition(SecureShareMetadataListener::class))
            ->setArgument('$tableName', $storageName)
            ->setArgument('$userClass', $userClass)
            ->setArgument('$accessLogTableName', $accessLogEnabled ? $accessLogTable : null)
            ->setArgument('$userMapping', $config['user_mapping'])
            // REQ-SEC-006 (1.20.4): warns when MySQL only offers the PAD SPACE utf8mb4_bin for the creator id column
            ->setArgument('$logger', new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));

        if ($accessLogEnabled) {
            $container->setDefinition(DoctrineOrmShareAccessLogRepository::class, (new Definition(DoctrineOrmShareAccessLogRepository::class))
                ->setAutowired(false)
                ->setArgument('$entityManager', new Reference(sprintf('doctrine.orm.%s_entity_manager', $entityManagerName)))
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
