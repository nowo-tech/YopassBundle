<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Closure;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\Filter\BsonFilter;
use Doctrine\ODM\MongoDB\Repository\DefaultRepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function array_keys;
use function count;
use function sprintf;

/**
 * The bundle's own document manager for shares on the MongoDB ODM driver (1.22.0, opt-in with
 * `nowo_yopass.database.private_document_manager: true`; the only mode in 2.0, REQ-ARCH-001 rule 6).
 *
 * The bundle then never flushes, clears, closes or resets a document manager of the application. Shares are read and
 * written through a **private** document manager created on the client (same server, same database and collection),
 * configuration (mapping, metadata cache, proxies and hydrators; its own repository factory) and event manager of
 * `nowo_yopass.database.document_manager` (`default`). Its unit of work holds only bundle documents, so a share write
 * never writes a change the application left pending, and the application's identity map never holds a share.
 *
 * The same lifecycle as the ORM private manager ({@see YopassEntityManager}), worker-safe without `kernel.reset`
 * (REQ-RUNTIME-002 rule 4). The private manager is replaced on the next call when it was closed or the source
 * manager's client changed, and dropped by {@see reset()} at every OUTERMOST unit-of-work boundary
 * ({@see YopassEntityManagerBoundarySubscriber}: main request, Messenger message, outermost console command) and at
 * `kernel.reset`. Unflushed bundle work is discarded and logged as a warning (a count, never a value).
 */
final class YopassDocumentManager implements ResetInterface
{
    private ?DocumentManager $documentManager = null;

    /**
     * @param ManagerRegistry $registry Doctrine MongoDB registry (`doctrine_mongodb`; the source manager is resolved per call)
     * @param string $documentManagerName `nowo_yopass.database.document_manager`
     * @param LoggerInterface|null $logger warns when a boundary discards unflushed bundle work
     */
    public function __construct(
        private readonly ManagerRegistry $registry,
        private readonly string $documentManagerName = 'default',
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The open private document manager, created (again) when needed.
     */
    public function get(): DocumentManager
    {
        $source  = $this->source();
        $current = $this->documentManager;
        if (!$current instanceof DocumentManager || !$current->isOpen() || $current->getClient() !== $source->getClient()) {
            $this->clearQuietly($current);

            // Same mapping, caches, proxies and hydrators, but a repository factory owned by this manager: the shared
            // factories (DoctrineMongoDBBundle's ContainerRepositoryFactory, ODM's DefaultRepositoryFactory) keep one
            // repository per manager instance for the life of the process, so every replaced private manager would
            // stay in memory in a worker.
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new DefaultRepositoryFactory());

            // @igor-ignore - Bundle-owned unit of work: dropped at every outermost boundary, replaced when closed or on another client.
            $current = $this->documentManager = DocumentManager::create($source->getClient(), $configuration, $source->getEventManager());
        }
        $this->syncFilters($source, $current);

        return $current;
    }

    /**
     * The application's manager whose client, configuration and events the private one shares. Never flushed.
     */
    public function source(): DocumentManager
    {
        $source = $this->registry->getManager($this->documentManagerName);
        if (!$source instanceof DocumentManager) {
            throw new LogicException(sprintf('nowo_yopass: document manager "%s" is not a Doctrine MongoDB ODM document manager.', $this->documentManagerName));
        }

        return $source;
    }

    /**
     * A `user_class` document managed by the application's manager as a reference in the private manager (same class
     * and id, no query), so the share's creator reference never points into another unit of work. Unchanged when the
     * private manager already manages it or it has no identifier yet.
     */
    public function reference(object $user, string $class): object
    {
        $manager = $this->get();
        if ($manager->contains($user)) {
            return $user;
        }
        /** @var class-string $class */
        $id = $manager->getClassMetadata($class)->getIdentifierValue($user);

        return $id === null ? $user : $manager->getReference($class, $id);
    }

    /**
     * The creator as the application's document manager sees it (for the events): `getReference(user_class, id)` on
     * `database.document_manager` for a creator the private manager manages, otherwise the creator unchanged.
     */
    public function forHost(object $creator): object
    {
        $private = $this->get();
        if (!$private->contains($creator)) {
            return $creator;
        }

        $class = $private->getClassMetadata($creator::class)->getName();
        $id    = $private->getUnitOfWork()->getDocumentIdentifier($creator);

        return $id === null ? $creator : $this->source()->getReference($class, $id);
    }

    /**
     * Whether a private manager exists right now (no side effect).
     */
    public function isCreated(): bool
    {
        return $this->documentManager instanceof DocumentManager;
    }

    /**
     * Scheduled inserts, upserts, updates and deletes of the private manager (0 when it does not exist or is closed;
     * no manager is created). Read from what the unit of work already scheduled: no change set is computed.
     */
    public function pendingCount(): int
    {
        $manager = $this->documentManager;
        if (!$manager instanceof DocumentManager || !$manager->isOpen()) {
            return 0;
        }

        return $this->scheduled($manager);
    }

    /**
     * The BSON filters enabled on the source manager, with their parameters, are enabled on the private one (and the
     * others disabled), on every call: the private manager is not configured by DoctrineMongoDBBundle, and the
     * application may enable or change a filter per request (tenant, soft delete).
     */
    private function syncFilters(DocumentManager $source, DocumentManager $target): void
    {
        $wanted  = $source->getFilterCollection()->getEnabledFilters();
        $filters = $target->getFilterCollection();
        foreach (array_keys($filters->getEnabledFilters()) as $name) {
            if (!isset($wanted[$name])) {
                $filters->disable($name);
            }
        }

        $read = $this->parameterReader();
        foreach ($wanted as $name => $filter) {
            $parameters = $read($filter);
            // A filter registered on the source after this manager was created (or again with another class) is
            // registered on the copy too.
            if ($target->getConfiguration()->getFilterClassName($name) !== $filter::class) {
                if ($filters->isEnabled($name)) {
                    $filters->disable($name);
                }
                $target->getConfiguration()->addFilter($name, $filter::class);
            }
            if ($filters->isEnabled($name)) {
                $copy = $filters->getFilter($name);
                if ($copy::class === $filter::class && $read($copy) === $parameters) {
                    continue;
                }
                // A fresh instance: a parameter the source no longer has (a previous tenant) must not survive.
                $filters->disable($name);
            }
            $copy = $filters->enable($name);
            foreach ($parameters as $parameter => $value) {
                $copy->setParameter($parameter, $value);
            }
        }
    }

    /**
     * Reads a BSON filter's parameters (ODM offers getParameter() by name only).
     *
     * @return Closure(BsonFilter): array<string, mixed>
     */
    private function parameterReader(): Closure
    {
        /** @var Closure(BsonFilter): array<string, mixed> $reader */
        $reader = Closure::bind(static fn (BsonFilter $filter): array => $filter->parameters, null, BsonFilter::class);

        return $reader;
    }

    /**
     * Detaches every document from the private manager. Never touches an application manager and never creates the
     * private one.
     */
    public function clear(): void
    {
        if ($this->documentManager instanceof DocumentManager && $this->documentManager->isOpen()) {
            $this->documentManager->clear();
        }
    }

    /**
     * Drops the private manager (an outermost unit-of-work boundary or `kernel.reset`); the next call creates a fresh
     * one. Unflushed bundle changes of the finished unit are discarded (logged as a warning), never written later.
     */
    public function reset(): void
    {
        $dropped = $this->documentManager;
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->documentManager = null;
        if (!$dropped instanceof DocumentManager || !$dropped->isOpen()) {
            return;
        }

        try {
            // The dropped manager is never used again: computing its change sets tells modified shares apart.
            $dropped->getUnitOfWork()->computeChangeSets();
            $pending = $this->scheduled($dropped);
            if ($pending > 0) {
                $this->logger?->warning('Discarding {count} unflushed Yopass share change(s) at a unit-of-work boundary.', ['count' => $pending]);
            }
            $dropped->clear();
        } catch (Throwable $exception) {
            $this->logger?->warning('Could not clear the dropped Yopass document manager at a unit-of-work boundary.', ['exception' => $exception::class]);
        }
    }

    /**
     * Clears a manager about to be replaced; a failure never escapes.
     */
    private function clearQuietly(?DocumentManager $manager): void
    {
        if (!$manager instanceof DocumentManager || !$manager->isOpen()) {
            return;
        }

        try {
            $manager->clear();
        } catch (Throwable $exception) {
            $this->logger?->warning('Could not clear the replaced Yopass document manager.', ['exception' => $exception::class]);
        }
    }

    private function scheduled(DocumentManager $manager): int
    {
        $unitOfWork = $manager->getUnitOfWork();

        return count($unitOfWork->getScheduledDocumentInsertions()) + count($unitOfWork->getScheduledDocumentUpserts())
            + count($unitOfWork->getScheduledDocumentUpdates()) + count($unitOfWork->getScheduledDocumentDeletions());
    }
}
