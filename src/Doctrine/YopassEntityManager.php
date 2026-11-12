<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Repository\DefaultRepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Contracts\Service\ResetInterface;

use function array_keys;
use function is_array;
use function is_string;
use function sprintf;
use function unserialize;

/**
 * The bundle's own entity manager for shares and the access log (1.19.0, REQ-ARCH-001 rule 6).
 *
 * The bundle never flushes, clears, closes or resets an entity manager of the application. Shares and access log rows
 * are read and written through a **private** entity manager created on the connection, configuration (mapping,
 * metadata cache, proxies; its own repository factory since 1.20.0) and event manager of
 * `nowo_yopass.database.entity_manager` (`default`). Its unit of work
 * holds only bundle rows, so a share write never commits a change the application left pending, and a failed write
 * closes only this private manager. The tables do not move: the private manager uses the same connection and mapping.
 * The connection is shared, so a transaction the application opened around a bundle call includes the bundle's rows.
 *
 * Worker-safe without `kernel.reset` (REQ-RUNTIME-002 rule 4). The private manager is replaced on the next call when:
 *
 * - it was closed (its own failed flush) or the source connection changed;
 * - a rollback (transaction or savepoint) happened on the connection since it was created, so its identity map may
 *   describe rows that no longer exist (1.20.0, {@see PrivateEntityManagerMiddleware}, doctrine/dbal 4). A stale
 *   manager that still has scheduled inserts, updates or deletes is **kept** (replacing it would silently drop them)
 *   until that work is flushed or the next boundary;
 * - {@see reset()} ran: at every OUTERMOST unit-of-work boundary ({@see YopassEntityManagerBoundarySubscriber}, 1.20.0:
 *   main request, Messenger message, outermost console command; a command, message or request run in-process inside
 *   another unit never drops the outer unit's manager) and at `kernel.reset`. Unflushed bundle work is discarded and
 *   logged as a warning.
 *
 * Before 1.20.0 this class subscribed itself and cleared the manager at every `kernel.terminate`, `console.terminate`
 * and Messenger handled / failed event, including those of a command or request run in-process inside another unit.
 */
final class YopassEntityManager implements ResetInterface
{
    private ?EntityManagerInterface $entityManager = null;

    /** A rollback happened on the connection since the private manager was created (1.20.0). */
    private bool $stale = false;

    /**
     * @param ManagerRegistry $registry Doctrine registry (the source manager is resolved per call)
     * @param string $entityManagerName `nowo_yopass.database.entity_manager`
     * @param LoggerInterface|null $logger warns when a boundary discards unflushed bundle work (1.20.0)
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $entityManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The open private entity manager, created (again) when needed.
     */
    public function get(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->entityManager;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()
            || ($this->stale && PendingWork::count($current) === 0)) {
            if ($current instanceof EntityManagerInterface && $current->isOpen()) {
                $current->clear();
            }
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();

            // Same mapping, caches and proxies, but a repository factory owned by this manager (1.20.0): the shared
            // factory (DoctrineBundle's ContainerRepositoryFactory, ORM's DefaultRepositoryFactory) keeps one repository
            // per manager instance for the life of the process, so every replaced private manager would stay in memory.
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new DefaultRepositoryFactory());

            // @igor-ignore - Bundle-owned unit of work: dropped at every outermost boundary, replaced when closed, stale or on another connection.
            $current = $this->entityManager = new EntityManager($source->getConnection(), $configuration, $eventManager instanceof EventManager ? $eventManager : null);
            // @igor-ignore - Bundle-owned flag of the private manager, reset when the manager is replaced.
            $this->stale = false;
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * The SQL filters enabled on the source manager, with their parameters, are enabled on the private one (and the
     * others disabled), on every call: the private manager is not built by DoctrineBundle's configurator, and the
     * application may enable or change a filter per request (tenant, soft delete; audit MO-Y-L2).
     */
    private function syncFilters(EntityManagerInterface $source, EntityManagerInterface $target): void
    {
        $wanted  = $source->getFilters()->getEnabledFilters();
        $filters = $target->getFilters();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }
        foreach ($wanted as $name => $filter) {
            $state = (string) $filter;
            if ($filters->isEnabled($name)) {
                if ((string) $filters->getFilter($name) === $state) {
                    continue;
                }
                // A fresh instance: a parameter the source no longer has (a previous tenant) must not survive (MO-Y-D-L1).
                $filters->disable($name);
            }
            $copy = $filters->enable($name);
            /** @var array<string, array{value: mixed, type: int|string|null, is_list?: bool}> $parameters */
            $parameters = unserialize($state);
            // A non-string type (DBAL ParameterType / int) can only come from ParameterTypeInferer, since
            // SQLFilter::setParameter() accepts a string type or null: passing null infers the same type again.

            foreach ($parameters as $parameter => $definition) {
                if (($definition['is_list'] ?? false) === true && is_array($definition['value'])) {
                    $copy->setParameterList($parameter, $definition['value'], is_string($definition['type']) ? $definition['type'] : 'string');
                } else {
                    $copy->setParameter($parameter, $definition['value'], is_string($definition['type']) ? $definition['type'] : null);
                }
            }
        }
    }

    /**
     * The application's manager whose connection, configuration and events the private one shares. Never flushed.
     */
    public function source(): EntityManagerInterface
    {
        $source = $this->registry->getManager($this->entityManagerName);
        if (!$source instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('nowo_yopass: entity manager "%s" is not a Doctrine ORM entity manager.', $this->entityManagerName));
        }

        return $source;
    }

    /**
     * Main request start: the previous request's shares are detached.
     *
     * @deprecated since 1.20.0, the boundaries are handled by {@see YopassEntityManagerBoundarySubscriber}; removed in 2.0
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clear();
        }
    }

    /**
     * Whether a private manager exists right now (no side effect).
     */
    public function isCreated(): bool
    {
        return $this->entityManager instanceof EntityManagerInterface;
    }

    /**
     * Whether a rollback made the private manager's identity map stale (no side effect).
     */
    public function isStale(): bool
    {
        return $this->stale;
    }

    /**
     * Scheduled inserts, updates and deletes of the private manager (0 when it does not exist or is closed; no manager
     * is created).
     */
    public function pendingCount(): int
    {
        return $this->entityManager instanceof EntityManagerInterface && $this->entityManager->isOpen() ? PendingWork::count($this->entityManager) : 0;
    }

    /**
     * A transaction or savepoint was rolled back on the connection (told by {@see PrivateEntityManagerConnection}).
     * The manager is replaced at its next use unless it has scheduled work; it is never cleared here (the rollback
     * may run inside the private manager's own failing flush).
     */
    public function connectionRolledBack(): void
    {
        if ($this->entityManager instanceof EntityManagerInterface) {
            // @igor-ignore - Bundle-owned flag of the private manager, cleared when the manager is replaced.
            $this->stale = true;
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager and never creates the private
     * one.
     */
    public function clear(): void
    {
        if ($this->entityManager instanceof EntityManagerInterface && $this->entityManager->isOpen()) {
            $this->entityManager->clear();
        }
    }

    /**
     * Drops the private manager (an outermost unit-of-work boundary or `kernel.reset`); the next call creates a fresh
     * one. Unflushed bundle changes of the finished unit are discarded (logged as a warning), never written later.
     */
    public function reset(): void
    {
        $pending = $this->pendingCount();
        if ($pending > 0) {
            $this->logger?->warning('Discarding {count} unflushed Yopass share change(s) at a unit-of-work boundary.', ['count' => $pending]);
        }
        $this->clear();
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->entityManager = null;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->stale = false;
    }
}
