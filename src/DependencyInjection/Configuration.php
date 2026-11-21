<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection;

use DateTimeImmutable;
use Nowo\TransactionDoctrine\Symfony\Conflict\ConflictResponsePolicy;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Database\DatabaseDriver;
use Nowo\YopassBundle\Support\RelativeInterval;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\ScalarNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_column;
use function array_key_exists;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function trim;

/**
 * Configuration tree for YopassBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_yopass';

    /**
     * `web_ui.icons.*` grammar, shared with the other kits (WikiBundle 1.18 `TAB_ICON_PATTERN`): "[prefix:]name" with
     * lowercase letters, digits and single hyphens (e.g. "tabler:file-text", "bi:pencil").
     */
    public const TAB_ICON_PATTERN = '/^[a-z0-9]+(?:-[a-z0-9]+)*(?::[a-z0-9]+(?:-[a-z0-9]+)*)?$/';

    /** Deprecation of the `private_entity_manager` / `private_document_manager` keys (2.0, removed in 3.0). */
    private const NO_EFFECT_MESSAGE = 'The "%node%" option at "%path%" has no effect since 2.0 (the private manager is the only mode); remove it.';

    /** Root keys removed in 2.0 (deprecated since 1.10) and what replaces each one. */
    private const REMOVED_ROOT_KEYS = [
        'route_prefix' => '"nowo_yopass.web_ui.path_prefix"',
        'templates'    => '"nowo_yopass.web_ui.layout_template" and "nowo_yopass.web_ui.templates.<page>"',
    ];

    /** Section keys removed in 2.0 (deprecated since 1.10) and what replaces each one. */
    private const REMOVED_SECTION_KEYS = [
        'security' => [
            'admin_roles'  => '"nowo_yopass.security.roles.admin"',
            'create_roles' => '"nowo_yopass.security.roles.create"',
            'list_roles'   => '"nowo_yopass.security.roles.list"',
            'revoke_roles' => '"nowo_yopass.security.roles.revoke"',
        ],
        'web_ui' => [
            'css_framework' => '"nowo_ui_kit.css_framework"',
            'icon_set'      => '"nowo_ui_kit.icon_set"',
        ],
    ];

    /**
     * Builds the "nowo_yopass" configuration tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root        = $treeBuilder->getRootNode();

        $root
            ->beforeNormalization()
                ->ifArray()
                ->then(static fn (array $config): array => self::refuseRemovedKeys($config))
            ->end();
        $this->addUserClassToAccessLogSections($root);
        $this->addSecurityHeadersToDashboardRouteSections($root);
        $this->addSharesSection($root);
        $this->addSharingSection($root);
        $this->addSecuritySection($root);
        $this->addWebUiSection($root);
        $this->addRoutesSection($root);
        $this->addTemplatesToConflictResponseSections($root);

        return $treeBuilder;
    }

    /**
     * Adds the `user_class` to `access_log` settings.
     */
    private function addUserClassToAccessLogSections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity (must implement UserInterface and expose getId()).')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->example('App\\Entity\\User')
                ->end()
                ->arrayNode('accepted_user_classes')
                    ->info('Security-user classes other than user_class that map to a creator by getId() (wrappers / read models carrying the user_class id). Empty (default): only user_class instances own and list shares; users of another identity domain fail closed.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->defaultValue([])
                ->end()
                ->enumNode('user_mapping')
                    ->info('How a share stores its creator: "relation" (default, Doctrine relation to user_class with ON DELETE CASCADE) or "id" (the creator id in a string column / field, no relation and no foreign key; user_class need not be mapped in the bundle\'s entity / document manager; delete the shares of a deleted user with CreatorIdShareRepositoryInterface::removeAllByCreatorId()) (1.18.0).')
                    ->values(CreatorMapping::values())
                    ->defaultValue(CreatorMapping::RELATION)
                ->end()
                ->scalarNode('table_prefix')
                    ->info('Prefix for the secure_shares table/collection (final name: {prefix}secure_shares).')
                    ->defaultValue('yopass_')
                    ->cannotBeEmpty()
                ->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->info('Persistence backend. Relational platforms use Doctrine ORM; MongoDB uses Doctrine ODM; custom allows any store.')
                    ->children()
                        ->enumNode('driver')
                            ->values(DatabaseDriver::drivers())
                            ->defaultValue(DatabaseDriver::DOCTRINE_ORM)
                            ->info('Persistence driver. Use custom with database.repository for Redis, Couchbase, etc.')
                        ->end()
                        ->enumNode('platform')
                            ->values(DatabaseDriver::platforms())
                            ->defaultValue(DatabaseDriver::PLATFORM_POSTGRESQL)
                            ->info('Documented platform (postgresql, mysql, mariadb, sqlite, sqlserver, oracle, mongodb, other).')
                        ->end()
                        ->scalarNode('connection')
                            ->defaultValue('default')
                            ->info('Doctrine DBAL connection name from config/packages/doctrine.yaml.')
                        ->end()
                        ->scalarNode('entity_manager')
                            ->defaultValue('default')
                            ->info('Doctrine ORM entity manager name for relational databases: the bundle\'s own entity manager is built on its connection, mapping, events and SQL filters (same tables). It is never flushed by the bundle.')
                        ->end()
                        ->booleanNode('private_entity_manager')
                            ->defaultTrue()
                            ->info('No effect since 2.0: the bundle always reads and writes through its own entity manager built on entity_manager. True is accepted (deprecated, removed in 3.0); false is refused.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '2.0', self::NO_EFFECT_MESSAGE)
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value === false)
                                ->thenInvalid('"false" is no longer supported since 2.0: the bundle always writes through its own entity manager (same connection and tables) and never flushes yours. Remove the option and flush your own changes yourself; see UPGRADING "To 2.0.0".')
                            ->end()
                        ->end()
                        ->enumNode('mapping')
                            ->values(['attribute', 'xml'])
                            ->defaultValue('attribute')
                            ->info('Doctrine ORM mapping of the bundle entities: "attribute" (default) or "xml" (src/Resources/config/doctrine, for hosts that map by XML only) (1.19.0). Same tables and columns.')
                        ->end()
                        ->scalarNode('document_manager')
                            ->defaultValue('default')
                            ->info('Doctrine MongoDB document manager name when platform/driver is mongodb.')
                        ->end()
                        ->booleanNode('private_document_manager')
                            ->defaultTrue()
                            ->info('No effect since 2.0: with the MongoDB ODM driver the bundle always reads and writes shares through its own document manager built on document_manager. True is accepted (deprecated, removed in 3.0); false is refused.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '2.0', self::NO_EFFECT_MESSAGE)
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value === false)
                                ->thenInvalid('"false" is no longer supported since 2.0: with the MongoDB ODM driver the bundle always writes through its own document manager (same database and collection) and never flushes yours. Remove the option and flush your own documents yourself; see UPGRADING "To 2.0.0".')
                            ->end()
                        ->end()
                        ->scalarNode('collection')
                            ->defaultNull()
                            ->info('MongoDB collection name. Defaults to {table_prefix}secure_shares.')
                        ->end()
                        ->scalarNode('repository')
                            ->defaultNull()
                            ->info('Service id implementing ShareRepositoryInterface when driver is custom.')
                        ->end()
                    ->end()
                ->end()
                ->integerNode('max_ciphertext_bytes')
                    ->info('Maximum accepted ciphertext payload size in bytes.')
                    ->defaultValue(700_000)
                    ->min(1024)
                ->end()
                ->integerNode('max_secret_chars')
                    ->info('Maximum characters allowed in the text secret field (multiline). Must stay within max_ciphertext_bytes after encryption.')
                    ->defaultValue(512 * 1024)
                    ->min(1)
                ->end()
                ->scalarNode('file_handler')
                    ->info('Service id implementing ShareFileHandlerInterface. When null, the file tab is hidden and file shares are rejected.')
                    ->defaultNull()
                ->end()
                ->arrayNode('access_log')
                    ->addDefaultsIfNotSet()
                    ->info('Records successful public link opens (consume) for the share creator audit trail. Requires Doctrine ORM.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('When false, opens are not persisted, the history UI stays hidden and the ShareAccessLog entity is not mapped (no table).')
                        ->end()
                        ->scalarNode('repository')
                            ->defaultNull()
                            ->info('Service id implementing ShareAccessLogRepositoryInterface, used with every database driver (MongoDB and custom included); the bundle then does not map the ShareAccessLog entity. Null (default): the ORM repository on relational platforms, none otherwise (1.18.0).')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `security_headers` to `dashboard_route` settings.
     */
    private function addSecurityHeadersToDashboardRouteSections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('security_headers')
                    ->addDefaultsIfNotSet()
                    ->info('Hardening headers on every bundle route (Cache-Control no-store, Referrer-Policy no-referrer, X-Robots-Tag noindex, X-Frame-Options DENY, CSP frame-ancestors none).')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Default true. Set false only when the host sends equivalent headers on the bundle routes.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('csp')
                    ->addDefaultsIfNotSet()
                    ->info('Content-Security-Policy helpers. libsodium compiles WebAssembly, so the pages that load yopass.js (manage, created, public reveal) need \'wasm-unsafe-eval\' in script-src.')
                    ->children()
                        ->booleanNode('wasm_unsafe_eval')
                            ->defaultFalse()
                            ->info('Opt-in: append \'wasm-unsafe-eval\' to script-src (or to a script-src derived from default-src) of the Content-Security-Policy / -Report-Only response headers on the yopass.js routes only. Never creates a policy and never touches other routes. Default false (the host adds it).')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('dashboard_route')
                    ->info('Optional route name for the back link in the manage UI. Null hides the button.')
                    ->defaultNull()
                ->end()
            ->end();
    }

    /**
     * Adds the `shares` section.
     */
    private function addSharesSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('shares')
                    ->addDefaultsIfNotSet()
                    ->info('Default and allowed expiration / max-read options for new shares.')
                    ->children()
                        ->scalarNode('default_expiration')
                            ->info('Default expiration option id (must match an expiration_options entry).')
                            ->defaultValue('1h')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('default_max_reads')
                            ->info('Default max reads (must be listed in max_reads_options).')
                            ->defaultValue(1)
                            ->min(1)
                            ->max(1000)
                        ->end()
                        ->integerNode('list_page_size')
                            ->info('Shares shown per page in the manage list. Use 0 to show all shares without pagination.')
                            ->defaultValue(20)
                            ->min(0)
                            ->max(500)
                        ->end()
                        ->arrayNode('max_reads_options')
                            ->integerPrototype()->min(1)->max(1000)->end()
                            ->defaultValue([1, 3, 10])
                            ->requiresAtLeastOneElement()
                            ->info('Allowed max-read values shown in the manage UI and accepted by the create API.')
                        ->end()
                        ->arrayNode('expiration_options')
                            ->arrayPrototype()
                                ->children()
                                    ->scalarNode('id')
                                        ->cannotBeEmpty()
                                        ->info('Stable identifier sent as expiresIn in the create API.')
                                    ->end()
                                    ->scalarNode('interval')
                                        ->cannotBeEmpty()
                                        ->info('PHP relative datetime modifier (e.g. "1 hour", "7 days").')
                                        ->validate()
                                            ->ifTrue(static fn (mixed $value): bool => !is_string($value) || RelativeInterval::seconds($value) === null)
                                            ->thenInvalid('Invalid expiration interval %s: expected a PHP relative datetime modifier that moves time forward (e.g. "1 hour", "7 days").')
                                        ->end()
                                    ->end()
                                ->end()
                            ->end()
                            ->defaultValue([
                                ['id' => '1h', 'interval' => '1 hour'],
                                ['id' => '24h', 'interval' => '24 hours'],
                                ['id' => '7d', 'interval' => '7 days'],
                            ])
                            ->requiresAtLeastOneElement()
                        ->end()
                        ->arrayNode('retention')
                            ->addDefaultsIfNotSet()
                            ->info('Automatically delete shares older than max_age: the creator\'s own when they create a share, and all of them via the purge console command (never on a list GET).')
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultTrue()
                                    ->info('When false, shares are kept until manually deleted or revoked.')
                                ->end()
                                ->scalarNode('max_age')
                                    ->defaultValue('1 month')
                                    ->cannotBeEmpty()
                                    ->info('PHP relative datetime modifier (e.g. "1 month", "30 days"). Shares with createdAt before now − max_age are removed.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static function (array $v): bool {
                            if (!($v['retention']['enabled'] ?? false)) {
                                return false;
                            }

                            return !RelativeInterval::retentionCutoff(new DateTimeImmutable(), (string) $v['retention']['max_age']) instanceof DateTimeImmutable;
                        })
                        ->thenInvalid('The "shares.retention.max_age" value must be a valid PHP relative datetime modifier that puts the cutoff in the past and after the year 1000, such as "14 days" or "1 month". A value such as "-14 days" or "1 month ago" would delete every live share.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => !in_array($v['default_expiration'], array_column($v['expiration_options'], 'id'), true))
                        ->thenInvalid('The "shares.default_expiration" value must match an "shares.expiration_options" id.')
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => !in_array($v['default_max_reads'], $v['max_reads_options'], true))
                        ->thenInvalid('The "shares.default_max_reads" value must be listed in "shares.max_reads_options".')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `sharing` section.
     */
    private function addSharingSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('sharing')
                    ->addDefaultsIfNotSet()
                    ->info('Default encryption and link-sharing options in the manage UI.')
                    ->children()
                        ->enumNode('default_encryption')
                            ->values(['auto', 'password'])
                            ->defaultValue('auto')
                            ->info('Default encryption mode: auto-generated key or custom password.')
                        ->end()
                        ->booleanNode('allow_custom_password')
                            ->defaultTrue()
                            ->info('When false, hides the custom password option in the create form.')
                        ->end()
                        ->booleanNode('default_embed_in_url')
                            ->defaultFalse()
                            ->info('Pre-check “include key/password in URL query string” when creating a share. Default false to avoid putting secrets in URLs.')
                        ->end()
                        ->booleanNode('allow_embed_in_url')
                            ->defaultTrue()
                            ->info('When false, hides the embed-in-URL option (short link only).')
                        ->end()
                        ->booleanNode('show_remember_notice')
                            ->defaultTrue()
                            ->info('Show the one-time / do-not-open-yourself notice after creating a share.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `security` section.
     */
    private function addSecuritySection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->info('Access control. Replace access_checker with a custom service implementing YopassAccessPolicyInterface.')
                    ->children()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing YopassAccessPolicyInterface. Null uses role-based defaults.')
                            ->defaultNull()
                        ->end()
                        ->arrayNode('access_roles')
                            ->scalarPrototype()->end()
                            ->defaultValue(['ROLE_ADMIN'])
                            ->info('Roles required to open the manage UI (an empty list denies everyone but security.roles.admin).')
                        ->end()
                        ->arrayNode('roles')
                            ->addDefaultsIfNotSet()
                            ->info('Roles per action (REQ-UI-003 security.roles.<action>). An empty list grants that action to nobody (only security.roles.admin).')
                            ->children()
                                ->arrayNode('admin')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                    ->info('Roles that bypass the feature checks (full access).')
                                ->end()
                                ->arrayNode('create')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                    ->info('Create shares.')
                                ->end()
                                ->arrayNode('list')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                    ->info('List and preview own shares.')
                                ->end()
                                ->arrayNode('revoke')
                                    ->scalarPrototype()->end()
                                    ->defaultValue(['ROLE_ADMIN'])
                                    ->info('Revoke, extend and delete own shares.')
                                ->end()
                            ->end()
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                            ->info('DEV/DEMO only: allow the manage UI without SecurityBundle or login. Never enable in production.')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `web_ui` section.
     */
    private function addWebUiSection(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->arrayNode('web_ui')
                    ->addDefaultsIfNotSet()
                    ->info('Web UI on the UiKit page contract (REQ-UI-001 / REQ-UI-003). The CSS stack, icon set and row-action display are inherited from nowo_ui_kit.')
                    ->children()
                        ->booleanNode('enabled')
                            ->defaultTrue()
                            ->info('Manage Web UI feature flag (default true). Access is still enforced by security.access_roles and the host firewall. The public share routes are always registered.')
                        ->end()
                        ->scalarNode('path_prefix')
                            ->defaultValue('')
                            ->info('Prefix prepended to every bundle route path, manage and public (e.g. /{_locale}). Replaces route_prefix.')
                        ->end()
                        ->enumNode('create_mode')
                            ->values(['page', 'dialog'])
                            ->defaultValue('page')
                            ->info('Where the create form lives: "page" (card above the list, POST + redirect to the created page; 1.11 behaviour) or "dialog" (a "New share" header / empty-state action opens a <dialog>; the browser encrypts, posts the ciphertext with fetch and shows the links in the dialog; the key stays in memory and is wiped on close).')
                        ->end()
                        ->arrayNode('list_routes')
                            ->scalarPrototype()->cannotBeEmpty()->end()
                            ->defaultValue([])
                            ->info('Host routes allowed to show the manage list (ShareListRouteEvent::setRoute(), e.g. a project "Secrets" tab). The pager, the filter form and the redirects after revoke / delete / delete all use the resolved route; action forms carry it as a token signed for the viewer and checked against this allow-list (never a free URL).')
                        ->end()
                        ->arrayNode('icons')
                            ->addDefaultsIfNotSet()
                            ->info('Icons of the create form\'s Text / File tabs (UiKit `tabs` item icon: a canonical kind or a prefixed name such as "tabler:file-text" / "bi:file-earmark-text" that the nowo_ui_kit.icon_set renders). The host tab style (data-tab-style: text, icon-text, icon) picks what shows. Null or "" = label only.')
                            ->children()
                                ->append($this->tabIconNode('create_tab_text', 'tabler:file-text', 'Icon of the "Text" tab.'))
                                ->append($this->tabIconNode('create_tab_file', 'tabler:file-upload', 'Icon of the "File" tab.'))
                            ->end()
                        ->end()
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout the manage pages extend (page.layout). Null = nowo_ui_kit.layouts.admin. The layout must render the UiKit canonical blocks.')
                        ->end()
                        ->scalarNode('public_layout_template')
                            ->defaultNull()
                            ->info('Layout of the public reveal page. Null = nowo_ui_kit.layouts.guest.')
                        ->end()
                        ->arrayNode('templates')
                            ->addDefaultsIfNotSet()
                            ->info('Per-page template overrides (a host template extending @!NowoYopassBundle/... and overriding a data block). Null = the bundle template.')
                            ->children()
                                ->scalarNode('manage')->defaultNull()->info('Manage page (list scaffold). Default @NowoYopassBundle/manage/index.html.twig.')->end()
                                ->scalarNode('created')->defaultNull()->info('Share created page (show scaffold). Default @NowoYopassBundle/manage/created.html.twig.')->end()
                                ->scalarNode('public')->defaultNull()->info('Public reveal page (unique page on the guest layout). Default @NowoYopassBundle/public/reveal.html.twig.')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `manage` to `public_show` settings.
     */
    private function addRoutesManageToPublicShowSections(ArrayNodeDefinition $routes): void
    {
        $routes
            ->children()
                ->arrayNode('manage')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_index')->end()
                    ->end()
                ->end()
                ->arrayNode('create')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/create')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_create')->end()
                    ->end()
                ->end()
                ->arrayNode('revoke')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/{id}/revoke')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_revoke')->end()
                    ->end()
                ->end()
                ->arrayNode('delete')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/{id}/delete')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_delete')->end()
                    ->end()
                ->end()
                ->arrayNode('delete_all')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/delete-all')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_delete_all')->end()
                    ->end()
                ->end()
                ->arrayNode('preview')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/{id}/preview')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_preview')->end()
                    ->end()
                ->end()
                ->arrayNode('extend')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/{id}/extend')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_extend')->end()
                    ->end()
                ->end()
                ->arrayNode('created')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/tools/yopass/{id}/created')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_created')->end()
                    ->end()
                ->end()
                ->arrayNode('public_show')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/share/{id}')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_public_share')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `public_consume` section.
     */
    private function addRoutesPublicConsumeSection(ArrayNodeDefinition $routes): void
    {
        $routes
            ->children()
                ->arrayNode('public_consume')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->defaultValue('/share/{id}/consume')->end()
                        ->scalarNode('name')->defaultValue('nowo_yopass_public_consume')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Adds the `routes` section; its large children have their own methods.
     */
    private function addRoutesSection(ArrayNodeDefinition $rootNode): void
    {
        $routes = $rootNode->children()->arrayNode('routes');
        $routes
            ->addDefaultsIfNotSet();
        $this->addRoutesManageToPublicShowSections($routes);
        $this->addRoutesPublicConsumeSection($routes);
    }

    /**
     * Adds the `templates` to `conflict_response` settings.
     */
    private function addTemplatesToConflictResponseSections(ArrayNodeDefinition $rootNode): void
    {
        $rootNode
            ->children()
                ->scalarNode('firewall')
                    ->info('Symfony firewall name where manage routes require authentication (documented for security.yaml).')
                    ->defaultValue('main')
                ->end()
                ->arrayNode('public_firewall_paths')
                    ->scalarPrototype()->end()
                    ->defaultValue(['^/share'])
                    ->info('Path patterns that must be publicly accessible (documented for security.yaml access_control).')
                ->end()
                ->arrayNode('public_rate_limit')
                    ->addDefaultsIfNotSet()
                    ->info('Rate limit for public share endpoints (consume/show) per client IP.')
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->integerNode('limit')->defaultValue(60)->min(1)->end()
                        ->integerNode('interval_seconds')->defaultValue(60)->min(1)->end()
                    ->end()
                ->end()
                ->arrayNode('conflict_response')
                    ->info('1.21.0 (REQ-RUNTIME-003): answer of a lock conflict (deadlock, lock wait timeout, serialization failure) on the bundle\'s routes. Unset: nowo-tech/transaction-doctrine\'s conflict_response when that bundle is registered, else 409 with Retry-After 1.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->integerNode('status')
                            ->info('409 (default, CONFLICT_001 / CONFLICT_002) or 503. Null: follow transaction-doctrine, else 409.')
                            ->defaultNull()
                            ->validate()
                                ->ifTrue(static fn (mixed $value): bool => $value !== null && !in_array($value, ConflictResponsePolicy::STATUSES, true))
                                ->thenInvalid('nowo_yopass.conflict_response.status must be 409 or 503, got %s.')
                            ->end()
                        ->end()
                        ->integerNode('retry_after')
                            ->info('Retry-After seconds of a CONFLICT_001 answer. Null: follow transaction-doctrine, else 1.')
                            ->defaultNull()
                            ->min(0)
                        ->end()
                        ->enumNode('commit_tracking')
                            ->values(['bundle', 'all', 'off'])
                            ->defaultValue('bundle')
                            ->info('Commits counted for CONFLICT_002 when nowo-tech/transaction-doctrine\'s commit_detection is off: bundle (default, the connection of database.entity_manager only), all (every Doctrine connection) or off (no middleware; only the failure itself and transaction-doctrine\'s request mark decide). With commit_detection on, transaction-doctrine\'s ledger is reused and the bundle adds no middleware.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('events')
                    ->info('2.1.0 (REQ-ARCH-001 rule 7): when the lifecycle events are dispatched.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('after_host_commit')
                            ->info('Doctrine ORM driver with doctrine/dbal 4: when the application wrapped a bundle write in its own transaction, the share_created / deleted / revoked / extended / consumed / bulk_deleted events wait for that transaction\'s commit (and are dropped on rollback). Without an open transaction they are dispatched at once, as before. false: always at once (2.0 behaviour).')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Refuses the keys removed in 2.0 (deprecated since 1.10) with the key that replaces each one, instead of the generic
     * "unrecognized option" error.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function refuseRemovedKeys(array $config): array
    {
        $removed = [];
        foreach (self::REMOVED_ROOT_KEYS as $key => $replacement) {
            if (array_key_exists($key, $config)) {
                $removed[$key] = $replacement;
            }
        }
        foreach (self::REMOVED_SECTION_KEYS as $section => $keys) {
            $values = is_array($config[$section] ?? null) ? $config[$section] : [];
            foreach ($keys as $key => $replacement) {
                if (array_key_exists($key, $values)) {
                    $removed[$section . '.' . $key] = $replacement;
                }
            }
        }

        if ($removed !== []) {
            $lines = [];
            foreach ($removed as $key => $replacement) {
                $lines[] = sprintf('"%s.%s" (use %s)', self::ALIAS, $key, $replacement);
            }

            throw new InvalidConfigurationException(sprintf('These options were deprecated since 1.10 and are removed in 2.0: %s. See UPGRADING "To 2.0.0".', implode(', ', $lines)));
        }

        return $config;
    }

    /**
     * `web_ui.icons.<tab>`: an icon name ({@see self::TAB_ICON_PATTERN}, checked when the container is built, so a
     * malformed name never fails at render time; surrounding spaces are trimmed), or null / '' for no icon.
     */
    private function tabIconNode(string $name, string $default, string $info): ScalarNodeDefinition
    {
        $node = new ScalarNodeDefinition($name);
        $node
            ->defaultValue($default)
            ->info($info)
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => $value !== null && !is_string($value))
                ->thenInvalid('Expected an icon name (string) or null, got %s.')
            ->end()
            ->validate()
                ->ifTrue(static fn (mixed $value): bool => is_string($value) && trim($value) !== '' && preg_match(self::TAB_ICON_PATTERN, trim($value)) !== 1)
                ->thenInvalid('Invalid icon name %s: expected "[prefix:]name" with lowercase letters, digits and single hyphens (e.g. "tabler:file-text", "bi:pencil"), or null / "" for no icon.')
            ->end();

        return $node;
    }
}
