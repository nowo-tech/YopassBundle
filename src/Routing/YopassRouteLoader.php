<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Routing;

use Nowo\YopassBundle\Controller\PublicShareController;
use Nowo\YopassBundle\Controller\ShareManageController;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Attribute\Route as RouteAttribute;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

use function is_string;
use function sprintf;

/**
 * Loads Yopass HTTP routes from controller {@see RouteAttribute} declarations (REQ-SF-004),
 * then applies {@code nowo_yopass.routes} path/name overrides and {@code route_prefix}.
 *
 * Do not also import the controllers via the attribute routing type or routes will
 * be registered twice.
 *
 * Uses public attribute properties (Symfony 7 + 8.1); getters were removed in Routing 8.1.
 */
final class YopassRouteLoader extends Loader
{
    /**
     * Config key => default attribute route name (must match controller #[Route] name=).
     *
     * @var array<string, string>
     */
    private const CONFIG_KEY_BY_DEFAULT_NAME = [
        'nowo_yopass_index'          => 'manage',
        'nowo_yopass_create'         => 'create',
        'nowo_yopass_revoke'         => 'revoke',
        'nowo_yopass_delete'         => 'delete',
        'nowo_yopass_delete_all'     => 'delete_all',
        'nowo_yopass_preview'        => 'preview',
        'nowo_yopass_extend'         => 'extend',
        'nowo_yopass_created'        => 'created',
        'nowo_yopass_public_share'   => 'public_show',
        'nowo_yopass_public_consume' => 'public_consume',
    ];

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
        // No instance "$loaded" flag: FrankenPHP worker reuses the container; a sticky
        // flag breaks route reloads after cache:clear (REQ-DEMO-010 / REQ-SF-004).
        $collection = new RouteCollection();

        foreach ([ShareManageController::class, PublicShareController::class] as $controllerClass) {
            $this->addRoutesFromController($collection, $controllerClass);
        }

        return $collection;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === 'nowo_yopass';
    }

    /**
     * @param class-string $controllerClass
     */
    private function addRoutesFromController(RouteCollection $collection, string $controllerClass): void
    {
        $reflection = new ReflectionClass($controllerClass);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() !== $controllerClass) {
                continue;
            }

            /** @var list<ReflectionAttribute<RouteAttribute>> $attributes */
            $attributes = $method->getAttributes(RouteAttribute::class);

            foreach ($attributes as $attribute) {
                $routeAttr   = $attribute->newInstance();
                $defaultName = $routeAttr->name;

                if ($defaultName === null || $defaultName === '' || !isset(self::CONFIG_KEY_BY_DEFAULT_NAME[$defaultName])) {
                    throw new RuntimeException(sprintf('Yopass route attribute on %s::%s must declare a known name.', $controllerClass, $method->getName()));
                }

                $configKey = self::CONFIG_KEY_BY_DEFAULT_NAME[$defaultName];

                if (!isset($this->routes[$configKey])) {
                    throw new RuntimeException(sprintf('Missing nowo_yopass.routes.%s configuration for attribute route "%s".', $configKey, $defaultName));
                }

                $config  = $this->routes[$configKey];
                $path    = $this->routePrefix . $config['path'];
                $methods = $this->normalizeStringList($routeAttr->methods);

                if ($methods === []) {
                    $methods = ['GET'];
                }

                $defaults                = $routeAttr->defaults;
                $defaults['_controller'] = $controllerClass . '::' . $method->getName();

                $collection->add(
                    $config['name'],
                    new Route(
                        $path,
                        $defaults,
                        $routeAttr->requirements,
                        $routeAttr->options,
                        (string) ($routeAttr->host ?? ''),
                        $this->normalizeStringList($routeAttr->schemes),
                        $methods,
                        (string) ($routeAttr->condition ?? ''),
                    ),
                );
            }
        }
    }

    /**
     * @param array<string>|string $value
     *
     * @return list<string>
     */
    private function normalizeStringList(array|string $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [$value];
        }

        return array_values($value);
    }
}
