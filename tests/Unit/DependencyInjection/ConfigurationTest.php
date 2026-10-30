<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaultRoutesAndTablePrefix(): void
    {
        $config = $this->process([
            'user_class' => 'App\\Entity\\User',
        ]);

        self::assertSame('yopass_', $config['table_prefix']);
        self::assertSame('default', $config['database']['connection']);
        self::assertSame('doctrine_orm', $config['database']['driver']);
        self::assertSame('postgresql', $config['database']['platform']);
        self::assertSame('nowo_yopass_index', $config['routes']['manage']['name']);
        self::assertSame('/tools/yopass/{id}/preview', $config['routes']['preview']['path']);
        self::assertSame('/tools/yopass/{id}/delete', $config['routes']['delete']['path']);
        self::assertSame('/tools/yopass/delete-all', $config['routes']['delete_all']['path']);
        self::assertSame('/tools/yopass/{id}/extend', $config['routes']['extend']['path']);
        self::assertSame('/tools/yopass/{id}/created', $config['routes']['created']['path']);
        self::assertSame('/share/{id}', $config['routes']['public_show']['path']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['roles']['admin']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['roles']['create']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['roles']['list']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['roles']['revoke']);
        self::assertNull($config['security']['create_roles']);
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertTrue($config['web_ui']['enabled']);
        self::assertSame('', $config['web_ui']['path_prefix']);
        self::assertNull($config['web_ui']['layout_template']);
        self::assertNull($config['web_ui']['public_layout_template']);
        self::assertSame(['manage' => null, 'created' => null, 'public' => null], $config['web_ui']['templates']);
        self::assertNull($config['web_ui']['css_framework']);
        self::assertNull($config['web_ui']['icon_set']);
        self::assertNull($config['route_prefix']);
        self::assertSame(['layout' => null, 'manage' => null, 'created' => null, 'public' => null], $config['templates']);
        self::assertNull($config['file_handler']);
        self::assertSame(512 * 1024, $config['max_secret_chars']);
        self::assertSame('1h', $config['shares']['default_expiration']);
        self::assertSame(1, $config['shares']['default_max_reads']);
        self::assertSame(20, $config['shares']['list_page_size']);
        self::assertSame([1, 3, 10], $config['shares']['max_reads_options']);
        self::assertCount(3, $config['shares']['expiration_options']);
        self::assertTrue($config['shares']['retention']['enabled']);
        self::assertSame('1 month', $config['shares']['retention']['max_age']);
        self::assertSame('auto', $config['sharing']['default_encryption']);
        self::assertTrue($config['sharing']['allow_custom_password']);
        self::assertFalse($config['sharing']['default_embed_in_url']);
    }

    public function testDeprecatedKeysAreMappedOntoTheCanonicalOnes(): void
    {
        $config = $this->process([
            'user_class'   => 'App\\Entity\\User',
            'route_prefix' => '/{_locale}',
            'templates'    => [
                'layout'  => 'base.html.twig',
                'manage'  => 'yopass/manage.html.twig',
                'created' => 'yopass/created.html.twig',
                'public'  => 'yopass/public.html.twig',
            ],
            'security' => [
                'admin_roles'  => ['ROLE_SUPER'],
                'create_roles' => ['ROLE_USER'],
                'list_roles'   => ['ROLE_USER'],
                'revoke_roles' => [],
            ],
        ]);

        self::assertSame('/{_locale}', $config['web_ui']['path_prefix']);
        self::assertSame('base.html.twig', $config['web_ui']['layout_template']);
        self::assertSame([
            'manage'  => 'yopass/manage.html.twig',
            'created' => 'yopass/created.html.twig',
            'public'  => 'yopass/public.html.twig',
        ], $config['web_ui']['templates']);
        self::assertSame(['ROLE_SUPER'], $config['security']['roles']['admin']);
        self::assertSame(['ROLE_USER'], $config['security']['roles']['create']);
        self::assertSame(['ROLE_USER'], $config['security']['roles']['list']);
        self::assertSame([], $config['security']['roles']['revoke']);
    }

    public function testCanonicalKeysWinOverDeprecatedOnes(): void
    {
        $config = $this->process([
            'user_class'   => 'App\\Entity\\User',
            'route_prefix' => '/legacy',
            'templates'    => ['layout' => 'legacy.html.twig', 'manage' => 'legacy/manage.html.twig'],
            'web_ui'       => [
                'path_prefix'     => '/canonical',
                'layout_template' => 'canonical.html.twig',
                'templates'       => ['manage' => 'canonical/manage.html.twig'],
                'css_framework'   => 'tailwind',
                'icon_set'        => 'svg_inline',
            ],
            'security' => [
                'create_roles' => ['ROLE_LEGACY'],
                'roles'        => ['create' => ['ROLE_CANONICAL']],
            ],
        ]);

        self::assertSame('/canonical', $config['web_ui']['path_prefix']);
        self::assertSame('canonical.html.twig', $config['web_ui']['layout_template']);
        self::assertSame('canonical/manage.html.twig', $config['web_ui']['templates']['manage']);
        self::assertSame('tailwind', $config['web_ui']['css_framework']);
        self::assertSame('svg_inline', $config['web_ui']['icon_set']);
        self::assertSame(['ROLE_CANONICAL'], $config['security']['roles']['create']);
    }

    public function testRejectsUnknownCssFramework(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->process(['user_class' => 'App\\Entity\\User', 'web_ui' => ['css_framework' => 'bulma']]);
    }

    public function testCustomShareOptions(): void
    {
        $config = $this->process([
            'user_class' => 'App\\Entity\\User',
            'shares'     => [
                'default_expiration' => '48h',
                'default_max_reads'  => 5,
                'max_reads_options'  => [1, 5, 20],
                'expiration_options' => [
                    ['id' => '48h', 'interval' => '48 hours'],
                    ['id' => '30d', 'interval' => '30 days'],
                ],
            ],
        ]);

        self::assertSame('48h', $config['shares']['default_expiration']);
        self::assertSame(5, $config['shares']['default_max_reads']);
        self::assertSame([1, 5, 20], $config['shares']['max_reads_options']);
    }

    public function testCustomTablePrefixAndRoutes(): void
    {
        $config = $this->process([
            'user_class'   => 'App\\Entity\\User',
            'table_prefix' => 'vault_',
            'routes'       => [
                'manage' => ['path' => '/yopass', 'name' => 'app_yopass_index'],
            ],
        ]);

        self::assertSame('vault_', $config['table_prefix']);
        self::assertSame('/yopass', $config['routes']['manage']['path']);
        self::assertSame('app_yopass_index', $config['routes']['manage']['name']);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function process(array $input): array
    {
        $processor = new Processor();

        return $processor->processConfiguration(new Configuration(), [$input]);
    }
}
