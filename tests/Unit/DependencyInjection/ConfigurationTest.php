<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\DependencyInjection;

use Nowo\YopassBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
        self::assertSame(['ROLE_ADMIN'], $config['security']['admin_roles']);
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
        self::assertTrue($config['sharing']['default_embed_in_url']);
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
