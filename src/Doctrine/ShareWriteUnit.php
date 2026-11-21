<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Closure;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

/**
 * One transaction per write unit of the bundle (1.21.0, REQ-RUNTIME-003 rule 1), for the Doctrine ORM driver: the
 * public consume (the read is spent and its access log row written together) and the owner's extend (max reads and
 * expiration together). Built on nowo-tech/transaction-doctrine's {@see DoctrineTransactionRunner}, one attempt, no
 * retry, on the bundle's private entity manager ({@see YopassEntityManager}, the only mode since 2.0), without a
 * registry: after a storage failure the private manager is cleared (or left closed and replaced at its next use); the
 * application's manager is never flushed, cleared, closed or reset.
 *
 * A deadlock, lock wait timeout or serialization failure is rethrown as {@see TransientStorageFailure} with no
 * Retry-After hint of its own (the configured `conflict_response.retry_after` applies); a nested call runs in a
 * savepoint of the caller's transaction. Stateless (FrankenPHP worker mode, kernel reset off): the private manager is
 * resolved on every call.
 *
 * @internal registered by the bundle for the Doctrine ORM driver only
 */
final readonly class ShareWriteUnit
{
    /**
     * @param YopassEntityManager $ownManager the bundle's private manager
     */
    public function __construct(
        private YopassEntityManager $ownManager,
    ) {
    }

    /**
     * Runs `$operation` in one transaction (flushing the private manager before the commit) and returns its result.
     *
     * @template T
     *
     * @param Closure(): T $operation
     *
     * @throws TransientStorageFailure on a lock conflict (nothing of the unit is committed)
     *
     * @return T
     */
    public function run(Closure $operation): mixed
    {
        return DoctrineTransactionRunner::forEntityManager($this->ownManager->get(), null, null, null)->run($operation);
    }
}
