<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Repository\DefaultRepositoryFactory;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;

use function count;
use function sprintf;

/**
 * The bundle's own document manager for shares on the MongoDB ODM driver (1.22.0 opt-in, the only mode since 2.0,
 * REQ-ARCH-001 rule 6).
 *
 * The bundle never flushes, clears, closes or resets a document manager of the application. Shares are read and
 * written through a **private** document manager created on the client (same server, same database and collection),
 * configuration (mapping, metadata cache, proxies and hydrators; its own repository factory) and event manager of
 * `nowo_yopass.database.document_manager` (`default`). Its BSON filters are the application's own filter collection
 * ({@see YopassPrivateDocumentManager}, audit XC-M1): the same filter instances, parameters and state, never a copy. Its unit of work holds only bundle documents, so a share write
 * never writes a change the application left pending, and the application's identity map never holds a share.
 *
 * The same lifecycle as the ORM private manager ({@see YopassEntityManager}), worker-safe without `kernel.reset`
 * (REQ-RUNTIME-002 rule 4). The private manager is replaced on the next call when it was closed or the source
 * manager changed (a registry reset), and dropped by {@see reset()} at every OUTERMOST unit-of-work boundary
 * ({@see YopassEntityManagerBoundarySubscriber}: main request, Messenger message, outermost console command) and at
 * `kernel.reset`. Unflushed bundle work is discarded and logged as a warning (a count, never a value).
 */
final class YopassDocumentManager implements ResetInterface
{
    private ?YopassPrivateDocumentManager $documentManager = null;

    /** The application's manager the private one was created on (a replaced source gets a new private manager). */
    private ?DocumentManager $source = null;

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
     *
     * Declared as the ODM `DocumentManager` (the instance is always the internal {@see YopassPrivateDocumentManager}):
     * Symfony's opcache preloader follows public signatures, and naming the subclass made it compile that class in
     * applications without doctrine/mongodb-odm ("Can't preload unlinked class ... Unknown parent DocumentManager").
     *
     * @return YopassPrivateDocumentManager
     */
    public function get(): DocumentManager
    {
        $source  = $this->source();
        $current = $this->documentManager;
        if (!$current instanceof YopassPrivateDocumentManager || !$current->isOpen() || $this->source !== $source) {
            $this->clearQuietly($current);

            // Same client, mapping, caches, proxies and hydrators, but a repository factory owned by this manager: the
            // shared factories (DoctrineMongoDBBundle's ContainerRepositoryFactory, ODM's DefaultRepositoryFactory) keep
            // one repository per manager instance for the life of the process, so every replaced private manager would
            // stay in memory in a worker.
            $configuration = clone $source->getConfiguration();
            $configuration->setRepositoryFactory(new DefaultRepositoryFactory());

            // @igor-ignore - Bundle-owned unit of work: dropped at every outermost boundary, replaced when closed or when the source manager changed.
            $current = YopassPrivateDocumentManager::create($source->getClient(), $configuration, $source->getEventManager());
            if (!$current instanceof YopassPrivateDocumentManager) {
                throw new LogicException('nowo_yopass: could not create the private document manager.'); // @codeCoverageIgnore
            }
            // The application's own filter collection (audit XC-M1): never a copy. Set before the manager is used.
            $current->useFiltersOf($source);
            // @igor-ignore - Bundle-owned unit of work, see above.
            $this->documentManager = $current;
            // @igor-ignore - Bundle-owned reference, dropped with the private manager.
            $this->source = $source;
        }

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
        // @igor-ignore - Boundary drop of the bundle's own manager (no state kept).
        $this->source = null;
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
