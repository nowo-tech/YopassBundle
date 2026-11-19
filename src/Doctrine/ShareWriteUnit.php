<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

/**
 * One transaction per write unit of the bundle (1.21.0, REQ-RUNTIME-003 rule 1), for the Doctrine ORM driver: the
 * public consume (the read is spent and its access log row written together) and the owner's extend (max reads and
 * expiration together). Built on nowo-tech/transaction-doctrine's {@see DoctrineTransactionRunner}, one attempt, no
 * retry:
 *
 * - with the bundle's private entity manager (the default, {@see YopassEntityManager}):
 *   `forEntityManager()` on that manager, without a registry: after a storage failure the private manager is cleared
 *   (or left closed and replaced at its next use); the application's manager is never touched;
 * - with `database.private_entity_manager: false` (deprecated): `forHostEntityManager()` on the application's manager,
 *   which never closes, clears or resets an open manager and replaces a closed one through the registry. It throws a
 *   {@see LogicException} when the application left changes pending on that manager before the unit (they would
 *   otherwise be committed, or lost, with the bundle's unit).
 *
 * A deadlock, lock wait timeout or serialization failure is rethrown as {@see TransientStorageFailure} with no
 * Retry-After hint of its own (the configured `conflict_response.retry_after` applies); a nested call runs in a
 * savepoint of the caller's transaction. Stateless (FrankenPHP worker mode, kernel reset off): the managers are
 * resolved on every call.
 *
 * @internal registered by the bundle for the Doctrine ORM driver only
 */
final readonly class ShareWriteUnit
{
    /**
     * @param EntityManagerInterface $entityManager the application's manager (`database.entity_manager`)
     * @param ManagerRegistry|null $registry replaces a closed application manager (legacy mode)
     * @param string|null $managerName `database.entity_manager`
     * @param YopassEntityManager|null $ownManager the bundle's private manager (null = legacy mode)
     */
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?YopassEntityManager $ownManager = null,
    ) {
    }

    /**
     * Runs `$operation` in one transaction (flushing the manager before the commit) and returns its result.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @throws TransientStorageFailure on a lock conflict (nothing of the unit is committed)
     * @throws LogicException in legacy mode, when the application's manager has pending changes
     *
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        if ($this->ownManager instanceof YopassEntityManager) {
            return DoctrineTransactionRunner::forEntityManager($this->ownManager->get(), null, null, null)->run($operation);
        }

        return DoctrineTransactionRunner::forHostEntityManager($this->hostManager(), $this->registry, $this->managerName, null)->run($operation);
    }

    /**
     * The application's manager, fetched from the registry on every call; a closed one is replaced there first (the
     * same rule as the repositories: an open application manager is never reset).
     */
    private function hostManager(): EntityManagerInterface
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }
}
