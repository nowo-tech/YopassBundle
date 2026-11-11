<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function class_exists;
use function is_a;
use function is_string;

/**
 * Keeps `YopassAccessPolicyInterface` / `YopassAccessCheckerInterface` aliased to the configured access checker only
 * when its class implements that interface (1.19.0, audit MO-Y-I2), so autowiring an interface never receives a service
 * of another type. A class that cannot be resolved at compile time (factory, unknown class) keeps both aliases.
 */
final class AccessCheckerAliasPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        foreach ([YopassAccessPolicyInterface::class, YopassAccessCheckerInterface::class] as $interface) {
            if (!$container->hasAlias($interface)) {
                continue;
            }
            $id = (string) $container->getAlias($interface);
            if (!$container->has($id)) {
                continue;
            }
            $class = $container->getParameterBag()->resolveValue($container->findDefinition($id)->getClass() ?? $id);
            if (is_string($class) && class_exists($class) && !is_a($class, $interface, true)) {
                $container->removeAlias($interface);
            }
        }
    }
}
