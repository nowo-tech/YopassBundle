<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Routing;

use Nowo\YopassBundle\Controller\PublicShareController;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Routing\YopassRouteLoader;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class YopassRouteLoaderTest extends TestCase
{
    public function testLoadsConfiguredRoutesWithPrefix(): void
    {
        $loader = new YopassRouteLoader([
            'manage'         => ['path' => '/tools/yopass', 'name' => 'nowo_yopass_index'],
            'create'         => ['path' => '/tools/yopass/create', 'name' => 'nowo_yopass_create'],
            'revoke'         => ['path' => '/tools/yopass/{id}/revoke', 'name' => 'nowo_yopass_revoke'],
            'delete'         => ['path' => '/tools/yopass/{id}/delete', 'name' => 'nowo_yopass_delete'],
            'delete_all'     => ['path' => '/tools/yopass/delete-all', 'name' => 'nowo_yopass_delete_all'],
            'preview'        => ['path' => '/tools/yopass/{id}/preview', 'name' => 'nowo_yopass_preview'],
            'extend'         => ['path' => '/tools/yopass/{id}/extend', 'name' => 'nowo_yopass_extend'],
            'created'        => ['path' => '/tools/yopass/{id}/created', 'name' => 'nowo_yopass_created'],
            'public_show'    => ['path' => '/share/{id}', 'name' => 'nowo_yopass_public_share'],
            'public_consume' => ['path' => '/share/{id}/consume', 'name' => 'nowo_yopass_public_consume'],
        ], '/admin');

        $collection = $loader->load('.', 'nowo_yopass');

        self::assertTrue($loader->supports('.', 'nowo_yopass'));
        self::assertSame('/admin/tools/yopass', $collection->get('nowo_yopass_index')->getPath());
        self::assertSame(
            ShareManageController::class . '::index',
            $collection->get('nowo_yopass_index')->getDefault('_controller'),
        );
        self::assertSame(
            PublicShareController::class . '::consume',
            $collection->get('nowo_yopass_public_consume')->getDefault('_controller'),
        );
    }

    public function testSupportsOnlyNowoYopassType(): void
    {
        $loader = $this->loader();

        self::assertTrue($loader->supports('.', 'nowo_yopass'));
        self::assertFalse($loader->supports('.', 'other'));
    }

    public function testLoadThrowsWhenCalledTwice(): void
    {
        $loader = $this->loader();
        $loader->load('.', 'nowo_yopass');

        $this->expectException(RuntimeException::class);
        $loader->load('.', 'nowo_yopass');
    }

    /**
     * @return array<string, array{path: string, name: string}>
     */
    private function loader(): YopassRouteLoader
    {
        return new YopassRouteLoader([
            'manage'         => ['path' => '/tools/yopass', 'name' => 'nowo_yopass_index'],
            'create'         => ['path' => '/tools/yopass/create', 'name' => 'nowo_yopass_create'],
            'revoke'         => ['path' => '/tools/yopass/{id}/revoke', 'name' => 'nowo_yopass_revoke'],
            'delete'         => ['path' => '/tools/yopass/{id}/delete', 'name' => 'nowo_yopass_delete'],
            'delete_all'     => ['path' => '/tools/yopass/delete-all', 'name' => 'nowo_yopass_delete_all'],
            'preview'        => ['path' => '/tools/yopass/{id}/preview', 'name' => 'nowo_yopass_preview'],
            'extend'         => ['path' => '/tools/yopass/{id}/extend', 'name' => 'nowo_yopass_extend'],
            'created'        => ['path' => '/tools/yopass/{id}/created', 'name' => 'nowo_yopass_created'],
            'public_show'    => ['path' => '/share/{id}', 'name' => 'nowo_yopass_public_share'],
            'public_consume' => ['path' => '/share/{id}/consume', 'name' => 'nowo_yopass_public_consume'],
        ], '/admin');
    }
}
