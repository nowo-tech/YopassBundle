<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use ReflectionClass;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function is_string;
use function sprintf;
use function str_contains;

/**
 * Fails the container build on a host service that still uses the removed `YopassAccessCheckerInterface` (2.0): a class
 * registered by autodiscovery (an `#[AsAlias]` / `#[AsDecorator]` checker, any `App\` service) cannot be loaded, so
 * Symfony keeps its definition with only a "Class ... not found" error and removes it silently when nothing uses it.
 *
 * Also checks the configured access checker (`nowo_yopass.security.access_checker`, aliased as
 * `YopassAccessPolicyInterface`) when the container is built (2.0): its class must implement
 * {@see YopassAccessPolicyInterface}. `YopassAccessCheckerInterface` was removed in 2.0, so a class that still
 * implements it cannot even be loaded: the build fails with a message naming the class instead of a fatal error at
 * the first request. A service built by a factory, whose class is not known at compile time, is not checked.
 */
final class AccessCheckerAliasPass implements CompilerPassInterface
{
    /** The access port removed in 2.0 (deprecated since 1.19.0). */
    public const REMOVED_INTERFACE = 'Nowo\\YopassBundle\\Security\\YopassAccessCheckerInterface';

    public function process(ContainerBuilder $container): void
    {
        foreach ($container->getDefinitions() as $serviceId => $service) {
            foreach ($service->getErrors() as $error) {
                if (str_contains($error, self::REMOVED_INTERFACE)) {
                    throw new LogicException(sprintf('The service "%s" cannot be loaded because it still uses "%s", removed in 2.0 (%s). Implement "%s" instead, and alias or decorate that interface; see UPGRADING "To 2.0.0".', $serviceId, self::REMOVED_INTERFACE, $error, YopassAccessPolicyInterface::class));
                }
            }
        }

        if (!$container->hasAlias(YopassAccessPolicyInterface::class)) {
            return;
        }
        $id = (string) $container->getAlias(YopassAccessPolicyInterface::class);
        if (!$container->has($id)) {
            return;
        }
        $definition = $container->findDefinition($id);
        if ($definition->getFactory() !== null) {
            return;
        }
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);
        if (!is_string($class)) {
            return;
        }

        $reflection = $container->getReflectionClass($class, false);
        if (!$reflection instanceof ReflectionClass) {
            if (!str_contains($class, '\\')) {
                return; // a service id without a class, not a class name
            }

            throw new LogicException(sprintf('The class "%s" of the "nowo_yopass.security.access_checker" service "%s" cannot be loaded. If it still implements "%s" (removed in 2.0), implement "%s" instead; see UPGRADING "To 2.0.0".', $class, $id, self::REMOVED_INTERFACE, YopassAccessPolicyInterface::class));
        }
        if (!$reflection->implementsInterface(YopassAccessPolicyInterface::class)) {
            throw new LogicException(sprintf('The "nowo_yopass.security.access_checker" service "%s" (class "%s") must implement "%s" since 2.0 ("YopassAccessCheckerInterface" was removed); see UPGRADING "To 2.0.0".', $id, $class, YopassAccessPolicyInterface::class));
        }
    }
}
