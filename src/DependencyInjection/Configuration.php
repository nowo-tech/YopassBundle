<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection;

use DateTimeImmutable;
use Nowo\YopassBundle\Database\DatabaseDriver;
use Nowo\YopassBundle\Ui\CssFramework;
use Nowo\YopassBundle\Ui\IconSet;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_key_exists;
use function in_array;
use function is_array;

/**
 * Configuration tree for YopassBundle.
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_yopass';

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
                ->then(static fn (array $config): array => self::mapDeprecatedRootKeys($config))
            ->end()
            ->children()
                ->scalarNode('user_class')
                    ->info('FQCN of the application user entity (must implement UserInterface and expose getId()).')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->example('App\\Entity\\User')
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
                            ->info('Doctrine ORM entity manager name for relational databases.')
                        ->end()
                        ->scalarNode('document_manager')
                            ->defaultValue('default')
                            ->info('Doctrine MongoDB document manager name when platform/driver is mongodb.')
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
                            ->info('When false, opens are not persisted and the history UI stays hidden.')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode('route_prefix')
                    ->info('Deprecated since 1.10: use web_ui.path_prefix.')
                    ->defaultNull()
                    ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "nowo_yopass.web_ui.path_prefix" instead (REQ-UI-003).')
                ->end()
                ->scalarNode('dashboard_route')
                    ->info('Optional route name for the back link in the manage UI. Null hides the button.')
                    ->defaultNull()
                ->end()
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
                            ->info('Automatically delete shares older than max_age from the manage list and via the purge console command.')
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

                            return (new DateTimeImmutable())->modify('-' . $v['retention']['max_age']) === false;
                        })
                        ->thenInvalid('The "shares.retention.max_age" value must be a valid PHP relative datetime modifier.')
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
                ->arrayNode('security')
                    ->addDefaultsIfNotSet()
                    ->info('Access control. Replace access_checker with a custom service implementing YopassAccessCheckerInterface.')
                    ->beforeNormalization()
                        ->ifArray()
                        ->then(static fn (array $security): array => self::mapDeprecatedRoleKeys($security))
                    ->end()
                    ->children()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing YopassAccessCheckerInterface. Null uses role-based defaults.')
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
                        ->variableNode('admin_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.10: use security.roles.admin.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "security.roles.admin" instead (REQ-UI-003).')
                        ->end()
                        ->variableNode('create_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.10: use security.roles.create.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "security.roles.create" instead (REQ-UI-003).')
                        ->end()
                        ->variableNode('list_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.10: use security.roles.list.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "security.roles.list" instead (REQ-UI-003).')
                        ->end()
                        ->variableNode('revoke_roles')
                            ->defaultNull()
                            ->info('Deprecated since 1.10: use security.roles.revoke.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "security.roles.revoke" instead (REQ-UI-003).')
                        ->end()
                        ->booleanNode('allow_unauthenticated')
                            ->defaultFalse()
                            ->info('DEV/DEMO only: allow the manage UI without SecurityBundle or login. Never enable in production.')
                        ->end()
                    ->end()
                ->end()
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
                        ->scalarNode('layout_template')
                            ->defaultNull()
                            ->info('Layout the manage pages extend (page.layout). Null = nowo_ui_kit.layouts.admin. A 1.x layout without the UiKit canonical blocks is wrapped in a deprecated bridge.')
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
                        ->enumNode('css_framework')
                            ->values([null, ...CssFramework::values()])
                            ->defaultNull()
                            ->info('Deprecated since 1.10: set nowo_ui_kit.css_framework. When set (and nowo_ui_kit.css_framework is not), it is copied to nowo_ui_kit.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, set "nowo_ui_kit.css_framework" instead (REQ-UI-003).')
                        ->end()
                        ->enumNode('icon_set')
                            ->values([null, ...IconSet::values()])
                            ->defaultNull()
                            ->info('Deprecated since 1.10: set nowo_ui_kit.icon_set. When set (and nowo_ui_kit.icon_set is not), it is copied to nowo_ui_kit.')
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, set "nowo_ui_kit.icon_set" instead (REQ-UI-003).')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('routes')
                    ->addDefaultsIfNotSet()
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
                        ->arrayNode('public_consume')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->scalarNode('path')->defaultValue('/share/{id}/consume')->end()
                                ->scalarNode('name')->defaultValue('nowo_yopass_public_consume')->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('templates')
                    ->addDefaultsIfNotSet()
                    ->info('Deprecated since 1.10: use web_ui.layout_template and web_ui.templates.<page>.')
                    ->children()
                        ->scalarNode('layout')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "nowo_yopass.web_ui.layout_template" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('manage')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "nowo_yopass.web_ui.templates.manage" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('created')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "nowo_yopass.web_ui.templates.created" instead (REQ-UI-003).')
                        ->end()
                        ->scalarNode('public')
                            ->defaultNull()
                            ->setDeprecated('nowo-tech/yopass-bundle', '1.10', 'The "%node%" option at "%path%" is deprecated, use "nowo_yopass.web_ui.templates.public" instead (REQ-UI-003).')
                        ->end()
                    ->end()
                ->end()
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
            ->end();

        return $treeBuilder;
    }

    /**
     * Maps the deprecated root keys onto the canonical REQ-UI-003 keys (the canonical key wins when both are set):
     * route_prefix → web_ui.path_prefix, templates.layout → web_ui.layout_template, templates.<page> →
     * web_ui.templates.<page>. The deprecated nodes stay in the tree so their use still triggers a deprecation.
     *
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private static function mapDeprecatedRootKeys(array $config): array
    {
        $webUi = is_array($config['web_ui'] ?? null) ? $config['web_ui'] : [];

        if (isset($config['route_prefix']) && !array_key_exists('path_prefix', $webUi)) {
            $webUi['path_prefix'] = $config['route_prefix'];
        }

        $templates = is_array($config['templates'] ?? null) ? $config['templates'] : [];
        if (isset($templates['layout']) && !array_key_exists('layout_template', $webUi)) {
            $webUi['layout_template'] = $templates['layout'];
        }

        $pages = is_array($webUi['templates'] ?? null) ? $webUi['templates'] : [];
        foreach (['manage', 'created', 'public'] as $page) {
            if (isset($templates[$page]) && !array_key_exists($page, $pages)) {
                $pages[$page] = $templates[$page];
            }
        }
        if ($pages !== []) {
            $webUi['templates'] = $pages;
        }

        if ($webUi !== []) {
            $config['web_ui'] = $webUi;
        }

        return $config;
    }

    /**
     * Maps the deprecated security.<action>_roles keys onto security.roles.<action> (the canonical key wins).
     *
     * @param array<string, mixed> $security
     *
     * @return array<string, mixed>
     */
    private static function mapDeprecatedRoleKeys(array $security): array
    {
        $roles = is_array($security['roles'] ?? null) ? $security['roles'] : [];
        foreach (['admin', 'create', 'list', 'revoke'] as $action) {
            $legacy = $security[$action . '_roles'] ?? null;
            if ($legacy !== null && !array_key_exists($action, $roles)) {
                $roles[$action] = $legacy;
            }
        }
        if ($roles !== []) {
            $security['roles'] = $roles;
        }

        return $security;
    }
}
