<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Routing;

use Nowo\YopassBundle\Controller\PublicShareController;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Routing\YopassRouteLoader;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\RouteCollection;

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
        self::assertSame(['POST'], $collection->get('nowo_yopass_public_consume')->getMethods());
        self::assertSame(['GET'], $collection->get('nowo_yopass_index')->getMethods());
    }

    public function testSupportsOnlyNowoYopassType(): void
    {
        $loader = $this->loader();

        self::assertTrue($loader->supports('.', 'nowo_yopass'));
        self::assertFalse($loader->supports('.', 'other'));
    }

    public function testLoadCanBeCalledTwiceForFrankenPhpWorkerReload(): void
    {
        $loader = $this->loader();
        $first  = $loader->load('.', 'nowo_yopass');
        $second = $loader->load('.', 'nowo_yopass');

        self::assertCount(10, $first->all());
        self::assertCount(10, $second->all());
        self::assertSame(array_keys($first->all()), array_keys($second->all()));
        self::assertSame(
            $first->get('nowo_yopass_index')->getPath(),
            $second->get('nowo_yopass_index')->getPath(),
        );
    }

    public function testPrivateLoaderAddsDefaultGetWhenAttributeHasNoMethods(): void
    {
        $loader = new YopassRouteLoader([
            'manage' => ['path' => '/tools/yopass', 'name' => 'app_manage'],
        ], '/admin');
        $collection = new RouteCollection();

        $this->invokeAddRoutesFromController($loader, $collection, LoaderTestController::class);

        self::assertSame(['GET'], $collection->get('app_manage')->getMethods());
        self::assertSame('/admin/tools/yopass', $collection->get('app_manage')->getPath());
    }

    public function testPrivateLoaderFailsWhenConfigRouteIsMissing(): void
    {
        $loader = new YopassRouteLoader([], '/admin');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing nowo_yopass.routes.manage');

        $this->invokeAddRoutesFromController($loader, new RouteCollection(), LoaderTestController::class);
    }

    public function testPrivateLoaderFailsWhenAttributeNameIsUnknown(): void
    {
        $loader = $this->loader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must declare a known name');

        $this->invokeAddRoutesFromController($loader, new RouteCollection(), InvalidNamedLoaderTestController::class);
    }

    public function testNormalizeStringListReturnsEmptyListForEmptyString(): void
    {
        $loader = $this->loader();
        $method = new ReflectionMethod(YopassRouteLoader::class, 'normalizeStringList');
        $method->setAccessible(true);

        self::assertSame([], $method->invoke($loader, ''));
    }

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

    private function invokeAddRoutesFromController(
        YopassRouteLoader $loader,
        RouteCollection $collection,
        string $controllerClass,
    ): void {
        $method = new ReflectionMethod(YopassRouteLoader::class, 'addRoutesFromController');
        $method->setAccessible(true);
        $method->invoke($loader, $collection, $controllerClass);
    }
}

final class LoaderTestController
{
    #[Route('/tools/yopass', name: 'nowo_yopass_index')]
    public function index(): void
    {
    }
}

final class InvalidNamedLoaderTestController
{
    #[Route('/broken', name: 'broken_name')]
    public function index(): void
    {
    }
}
