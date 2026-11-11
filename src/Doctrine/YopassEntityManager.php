<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\Common\EventManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
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
 * metadata cache, proxies) and event manager of `nowo_yopass.database.entity_manager` (`default`). Its unit of work
 * holds only bundle rows, so a share write never commits a change the application left pending, and a failed write
 * closes only this private manager. The tables do not move: the private manager uses the same connection and mapping.
 * The connection is shared, so a transaction the application opened around a bundle call includes the bundle's rows.
 *
 * Worker-safe without `kernel.reset` (REQ-RUNTIME-002 rule 4): a closed private manager, or one whose connection is not
 * the source manager's any more, is replaced on the next call, and its identity map is emptied at every boundary (main
 * `kernel.request`, `kernel.terminate`, `console.terminate`, Messenger worker message handled / failed), so no managed
 * share outlives the request, command or message that loaded it.
 */
final class YopassEntityManager implements ResetInterface, EventSubscriberInterface
{
    private ?EntityManagerInterface $entityManager = null;

    /**
     * @param ManagerRegistry $registry Doctrine registry (the source manager is resolved per call)
     * @param string $entityManagerName `nowo_yopass.database.entity_manager`
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $entityManagerName = 'default',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST                                         => ['onKernelRequest', 2048],
            KernelEvents::TERMINATE                                       => ['clear', -2048],
            'console.terminate'                                           => ['clear', -2048],
            'Symfony\Component\Messenger\Event\WorkerMessageHandledEvent' => ['clear', -2048],
            'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'  => ['clear', -2048],
        ];
    }

    /**
     * The open private entity manager, created (again) when needed.
     */
    public function get(): EntityManagerInterface
    {
        $source  = $this->source();
        $current = $this->entityManager;
        if (!$current instanceof EntityManagerInterface || !$current->isOpen() || $current->getConnection() !== $source->getConnection()) {
            // DoctrineBundle's ContainerAwareEventManager extends EventManager; any other implementation gets a fresh one.
            $eventManager = $source->getEventManager();

            // @igor-ignore - Bundle-owned unit of work: emptied at every boundary, replaced when closed or when the connection changed.
            $current = $this->entityManager = new EntityManager($source->getConnection(), $source->getConfiguration(), $eventManager instanceof EventManager ? $eventManager : null);
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
            $copy = $filters->enable($name);
            /** @var array<string, array{value: mixed, type: int|string|null, is_list?: bool}> $parameters */
            $parameters = unserialize((string) $filter);
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
     */
    public function onKernelRequest(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->clear();
        }
    }

    /**
     * Detaches every row from the private manager. Never touches an application manager.
     */
    public function clear(): void
    {
        $this->entityManager?->clear();
    }

    /**
     * Drops the private manager (kernel.reset); the next call creates a fresh one.
     */
    public function reset(): void
    {
        $this->entityManager?->clear();
        $this->entityManager = null;
    }
}
