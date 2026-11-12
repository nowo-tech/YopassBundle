<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Closure;
use Doctrine\DBAL\Connection;
use Throwable;

use function bin2hex;
use function random_bytes;

/**
 * Runs one bulk DQL write of the bundle (consume a read, raise max reads, bulk deletes) inside a savepoint when the
 * application already opened a transaction on the connection (1.20.0, REQ-ARCH-001 rule 6, same helper as VaultBundle
 * 1.19 `VaultSavepoint`): a failing bundle statement then rolls back to the savepoint only, so it never leaves the
 * application's transaction aborted (PostgreSQL) and the application's own COMMIT keeps its rows. Outside a
 * transaction, or on a platform without savepoints, the write runs as before. Used with the private entity manager
 * only.
 *
 * @internal
 */
final class YopassSavepoint
{
    /**
     * @template T
     *
     * @param Closure(): T $write
     *
     * @return T
     */
    public static function write(Connection $connection, Closure $write): mixed
    {
        if (!$connection->isTransactionActive()) {
            return $write();
        }

        $platform = $connection->getDatabasePlatform();
        if (!$platform->supportsSavepoints()) {
            return $write(); // @codeCoverageIgnore
        }

        $name = 'nowo_yopass_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($name);
        try {
            $result = $write();
        } catch (Throwable $exception) {
            $connection->rollbackSavepoint($name);

            throw $exception;
        }
        if ($platform->supportsReleaseSavepoints()) {
            $connection->releaseSavepoint($name);
        }

        return $result;
    }
}
