<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Throwable;

use function preg_match;

/**
 * Reports rolled-back transactions and savepoints (and failed commits) to {@see YopassEntityManager}, and since 2.1.0
 * the outermost commit and the rollbacks (with the savepoint's nesting level) to {@see HostCommitEventRelay}.
 *
 * @internal
 */
final class PrivateEntityManagerConnection extends AbstractConnectionMiddleware
{
    /** `ROLLBACK TO SAVEPOINT x` (most platforms) and `ROLLBACK TRANSACTION x` (SQL Server). */
    private const SAVEPOINT_ROLLBACK = '/^\s*ROLLBACK\s+(?:TO\s+SAVEPOINT|TRAN(?:SACTION)?)\s+([A-Za-z0-9_]+)\s*;?\s*$/i';

    /** Savepoints of DBAL's nested transactions carry their nesting level (`DOCTRINE_2`, DBAL 3 `DOCTRINE2_SAVEPOINT_2`). */
    private const NESTED = '/^DOCTRINE(?:2_SAVEPOINT)?_(\d+)$/iD';

    /** The bundle's own statement savepoints ({@see YopassSavepoint}): no event is held inside one. */
    private const OWN_SAVEPOINT = '/^nowo_yopass_/i';

    public function __construct(
        Connection $connection,
        private readonly YopassEntityManager $entityManager,
        private readonly ?HostCommitEventRelay $events = null,
    ) {
        parent::__construct($connection);
    }

    public function commit(): void
    {
        try {
            parent::commit();
        } catch (Throwable $exception) {
            $this->entityManager->connectionRolledBack();
            $this->events?->connectionRolledBack(null);

            throw $exception;
        }
        $this->events?->connectionCommitted();
    }

    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $this->entityManager->connectionRolledBack();
            $this->events?->connectionRolledBack(null);
        }
    }

    public function exec(string $sql): int|string
    {
        $result = parent::exec($sql);
        if (preg_match(self::SAVEPOINT_ROLLBACK, $sql, $match) === 1) {
            $this->entityManager->connectionRolledBack();
            if (preg_match(self::OWN_SAVEPOINT, $match[1]) !== 1) {
                $this->events?->connectionRolledBack(preg_match(self::NESTED, $match[1], $level) === 1 ? (int) $level[1] : null);
            }
        }

        return $result;
    }
}
