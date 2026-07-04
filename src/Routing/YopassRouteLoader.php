<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Routing;

use Nowo\YopassBundle\Controller\PublicShareController;
use Nowo\YopassBundle\Controller\ShareManageController;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Loads Yopass routes from bundle configuration.
 */
final class YopassRouteLoader extends Loader
{
    private bool $loaded = false;

    /**
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly array $routes,
        private readonly string $routePrefix,
    ) {
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        if ($this->loaded) {
            throw new RuntimeException('Yopass routes already loaded.');
        }

        $this->loaded = true;
        $collection   = new RouteCollection();

        $collection->add(
            $this->routes['manage']['name'],
            $this->createRoute(
                $this->routes['manage']['path'],
                ['_controller' => ShareManageController::class . '::index'],
                ['GET'],
            ),
        );

        $collection->add(
            $this->routes['create']['name'],
            $this->createRoute(
                $this->routes['create']['path'],
                ['_controller' => ShareManageController::class . '::create'],
                ['POST'],
            ),
        );

        $collection->add(
            $this->routes['revoke']['name'],
            $this->createRoute(
                $this->routes['revoke']['path'],
                ['_controller' => ShareManageController::class . '::revoke'],
                ['POST'],
            ),
        );

        $collection->add(
            $this->routes['delete']['name'],
            $this->createRoute(
                $this->routes['delete']['path'],
                ['_controller' => ShareManageController::class . '::delete'],
                ['POST'],
            ),
        );

        $collection->add(
            $this->routes['delete_all']['name'],
            $this->createRoute(
                $this->routes['delete_all']['path'],
                ['_controller' => ShareManageController::class . '::deleteAll'],
                ['POST'],
            ),
        );

        $collection->add(
            $this->routes['preview']['name'],
            $this->createRoute(
                $this->routes['preview']['path'],
                ['_controller' => ShareManageController::class . '::preview'],
                ['GET'],
            ),
        );

        $collection->add(
            $this->routes['extend']['name'],
            $this->createRoute(
                $this->routes['extend']['path'],
                ['_controller' => ShareManageController::class . '::extend'],
                ['POST'],
            ),
        );

        $collection->add(
            $this->routes['created']['name'],
            $this->createRoute(
                $this->routes['created']['path'],
                ['_controller' => ShareManageController::class . '::created'],
                ['GET'],
            ),
        );

        $collection->add(
            $this->routes['public_show']['name'],
            $this->createRoute(
                $this->routes['public_show']['path'],
                ['_controller' => PublicShareController::class . '::show'],
                ['GET'],
            ),
        );

        $collection->add(
            $this->routes['public_consume']['name'],
            $this->createRoute(
                $this->routes['public_consume']['path'],
                ['_controller' => PublicShareController::class . '::consume'],
                ['POST'],
            ),
        );

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_yopass';
    }

    /**
     * @param list<string> $methods
     * @param array<string, mixed> $defaults
     */
    private function createRoute(string $path, array $defaults, array $methods): Route
    {
        $fullPath = $this->routePrefix . $path;

        return new Route($fullPath, $defaults, [], [], '', [], $methods);
    }
}
