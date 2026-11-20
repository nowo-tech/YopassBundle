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
 * Checks the configured access checker (`nowo_yopass.security.access_checker`, aliased as
 * `YopassAccessPolicyInterface`) when the container is built (2.0): its class must implement
 * {@see YopassAccessPolicyInterface}. `YopassAccessCheckerInterface` was removed in 2.0, so a class that still
 * implements it cannot even be loaded: the build fails with a message naming the class instead of a fatal error at
 * the first request. A service built by a factory, whose class is not known at compile time, is not checked.
 */
final class AccessCheckerAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
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

            throw new LogicException(sprintf('The class "%s" of the "nowo_yopass.security.access_checker" service "%s" cannot be loaded. If it still implements "Nowo\YopassBundle\Security\YopassAccessCheckerInterface" (removed in 2.0), implement "%s" instead; see UPGRADING "To 2.0.0".', $class, $id, YopassAccessPolicyInterface::class));
        }
        if (!$reflection->implementsInterface(YopassAccessPolicyInterface::class)) {
            throw new LogicException(sprintf('The "nowo_yopass.security.access_checker" service "%s" (class "%s") must implement "%s" since 2.0 ("YopassAccessCheckerInterface" was removed); see UPGRADING "To 2.0.0".', $id, $class, YopassAccessPolicyInterface::class));
        }
    }
}
