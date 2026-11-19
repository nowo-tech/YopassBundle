<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\DependencyInjection\Compiler;

use Nowo\TransactionDoctrine\WriteUnit\CommitLedger;
use Nowo\YopassBundle\DependencyInjection\YopassExtension;
use Nowo\YopassBundle\EventListener\YopassConflictListener;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_string;
use function preg_match;
use function sprintf;

/**
 * 1.21.0 (REQ-RUNTIME-003 rule 6): scopes the commit tracking of the conflict answer (same rule as ContactFormBundle 1.11, audit UE-CF-1).
 *
 * - nowo-tech/transaction-doctrine's bundle registered with `commit_detection` (its {@see CommitLedger} service exists):
 *   its own middleware already counts every commit, so the bundle's middleware and ledger are removed and the conflict
 *   listener reads transaction-doctrine's ledger. No connection is wrapped twice.
 * - `conflict_response.commit_tracking: bundle` (default): the bundle's middleware wraps only the connection of the
 *   bundle's entity manager (`database.entity_manager`, else the default one), never the host's other connections.
 * - `all`: every connection (the tag stays without a `connection` attribute).
 * - `off`: the extension registered nothing; this pass does nothing.
 *
 * Runs before DoctrineBundle's MiddlewaresPass (priority 0).
 */
final class YopassCommitTrackingPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(YopassExtension::COMMIT_TRACKING_MIDDLEWARE)) {
            return;
        }

        if ($container->has(CommitLedger::class)) {
            $container->removeDefinition(YopassExtension::COMMIT_TRACKING_MIDDLEWARE);
            $container->removeDefinition(YopassExtension::COMMIT_LEDGER_SERVICE);
            if ($container->hasDefinition(YopassConflictListener::class)) {
                $container->getDefinition(YopassConflictListener::class)->replaceArgument(1, new Reference(CommitLedger::class));
            }

            return;
        }

        $mode = $container->hasParameter(YopassExtension::COMMIT_TRACKING_PARAMETER) ? $container->getParameter(YopassExtension::COMMIT_TRACKING_PARAMETER) : 'bundle';
        if ($mode === 'all') {
            return;
        }

        $container->getDefinition(YopassExtension::COMMIT_TRACKING_MIDDLEWARE)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $this->bundleConnection($container)]);
    }

    /**
     * The connection of the bundle's entity manager; the default connection when it cannot be resolved.
     */
    private function bundleConnection(ContainerBuilder $container): string
    {
        $manager = $container->hasParameter(YopassExtension::ENTITY_MANAGER_PARAMETER) ? $container->getParameter(YopassExtension::ENTITY_MANAGER_PARAMETER) : null;
        if (!is_string($manager) || $manager === '') {
            $manager = $container->hasParameter('doctrine.default_entity_manager') ? $container->getParameter('doctrine.default_entity_manager') : 'default';
        }

        $id       = sprintf('doctrine.orm.%s_entity_manager', is_string($manager) ? $manager : 'default');
        $argument = $container->hasDefinition($id) ? ($container->getDefinition($id)->getArguments()[0] ?? null) : null;
        if ($argument instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $argument, $match) === 1) {
            return $match[1];
        }

        $default = $container->hasParameter('doctrine.default_connection') ? $container->getParameter('doctrine.default_connection') : null;

        return is_string($default) ? $default : 'default';
    }
}
