# Concurrency

How the bundle stays correct when the same share is written at the same time: two readers opening one link, an owner
extending a share while it is read, a retried request, two workers purging. This is
[REQ-RUNTIME-003](https://github.com/nowo-tech/bundles) ("safe writes under contention"), since 1.21.0.

## Table of contents

- [The one-time reveal](#the-one-time-reveal)
- [Write paths](#write-paths)
- [Conflict codes](#conflict-codes)
- [Host options](#host-options)
- [Entity managers and worker mode](#entity-managers-and-worker-mode)
- [Tests](#tests)
- [Residual risks](#residual-risks)

## The one-time reveal

The public consume (`POST /share/{id}/consume`) is the security-critical path: a share with N reads must give its
ciphertext to at most N readers, whatever the timing.

- The read is **one conditional UPDATE**: `reads_left = reads_left - 1` where the id matches, the share is not revoked,
  not expired and `reads_left > 0`. The database applies it atomically. Two concurrent reveals of a one-read share
  cannot both match: the second waits for the first one's row lock, then re-evaluates the condition on the committed
  row (InnoDB and PostgreSQL READ COMMITTED both do) and matches nothing. It answers `410` `{status: "consumed"}`.
  MongoDB uses the equivalent `findAndModify` with the same conditions.
- The read and its **access log row commit together** (Doctrine ORM driver, `ShareWriteUnit`). If the access log
  insert fails, or the request hits a lock conflict, the read is rolled back: nobody received the ciphertext, so
  nothing was spent. Before 1.21.0 the read committed on its own first, and a failure afterwards burnt the read with a
  `500`.
- The ciphertext is returned, and `nowo_yopass.share_consumed` is dispatched, **only after the commit**.
- A reveal that waits too long for another reveal's row lock answers `409` + `Retry-After` + `CONFLICT_001`
  (`{status: "conflict", code: "CONFLICT_001"}`): its own read was not taken. The reveal page shows a "try again" message;
  a retry either gets the ciphertext (the other reveal rolled back) or `410` (it committed).

## Write paths

| Path | Transaction | Concurrency control (rule 4) | Conflict answer (rule 6) |
| --- | --- | --- | --- |
| Public consume (`PublicShareController::consume`) | One unit: the read + the access log row (`ShareWriteUnit`); event after the commit | Conditional `UPDATE … reads_left > 0` (atomic decrement) | `409` JSON `{status, code}`: `CONFLICT_001` + `Retry-After`, or `CONFLICT_002` |
| Create (`ShareManageController::create`, `ShareCreator`) | The retention purge first (its own statements), then one flush for the new row; event after the flush | Random UUID primary key; no "only one" rule | Page: host error page with `409`; dialog (JSON): `{error, code}`. A purge conflict happens before the share exists |
| Extend (`ShareExtender`) | One unit: the max reads raise + the new expiration (`ShareWriteUnit`); event after the commit | Raise: conditional `UPDATE … reads_left + delta` where not revoked and `max_reads` unchanged (concurrent reads are never given back). Expiration: conditional `UPDATE … expires_at = :new` where not revoked and `expires_at < :new`, so it only moves forward | `409` JSON `{error, code}` |
| Revoke (`ShareRevoker`) | One flush (one transaction) | The ORM writes the changed column only (`revoked_at`), so a concurrent read or raise is not overwritten; every read and raise checks `revoked_at IS NULL` | Host error page with `409` |
| Delete one (`ShareDeleter::delete`) | One flush (one transaction) | Access log rows keep `share_ref` (`ON DELETE SET NULL`) | Host error page with `409` |
| Delete all / retention per creator (`removeAllByCreator`, `removeByCreatorOlderThan`) | One DQL DELETE per chunk of 500 ids (id mapping) or one DQL DELETE (relation mapping); inside a savepoint when the application opened a transaction | Exact creator match (`ExactKeyColumnsInterface` rule, LQ-Y-L1); idempotent | `CONFLICT_002` once a chunk committed, else `CONFLICT_001` |
| Retention purge (`removeOlderThan`, `nowo:yopass:purge-old-shares`) | Chunks of 500 primary keys, each its own statement; the cutoff is checked again in the DELETE | Idempotent; no range DELETE over `created_at` (rule 9: no long range locks on the hot table) | Console: the exception (exit code 1); rerun safely |
| Access log (`ShareAccessLogger`) | Inside the consume unit | Random UUID primary key | As the consume |

Every write unit runs on the bundle's private entity manager (see below). No HTTP call, mail or file I/O happens while a
row lock is held: the event listeners run after the commit.

## Conflict codes

A lock conflict is a deadlock, a lock wait timeout or a serialization failure (MySQL 1213 / 1205, MariaDB 1020,
PostgreSQL 40001 / 40P01 / 55P03), or a lost savepoint of unknown outcome. On a route of the bundle,
`YopassConflictListener` answers it instead of a `500`:

| Code | When | Status | Headers |
| --- | --- | --- | --- |
| `CONFLICT_001` | Nothing of the request was committed: retrying is safe | `409` (or `503`) | `X-Conflict-Code: CONFLICT_001`, `Retry-After` |
| `CONFLICT_002` | Part of the request may already be committed: read again, do not repeat blindly | `409` (or `503`) | `X-Conflict-Code: CONFLICT_002`, no `Retry-After` |

"Part committed" covers the whole request: a commit earlier in the same request on the bundle's connection (counted by
transaction-doctrine's commit-tracking middleware), anything transaction-doctrine's request mark saw when its bundle is
registered with `commit_detection`, a committed `ConcurrencyConflict`, or a lost savepoint with no deadlock behind it.

Shapes (existing keys kept, `code` added):

- public consume: `{"status": "conflict", "code": "CONFLICT_001"}` or `{"status": "conflict_committed", "code": "CONFLICT_002"}`, `Cache-Control: no-store`;
- manage JSON endpoints (extend, preview, and create from the dialog): `{"error": "conflict" | "conflict_committed", "code": …}`;
- pages: the application's error page, with the status and the headers above.

Clients key on `code` or on the `X-Conflict-Code` header, never on the message.

**Not conflicts:** unique-constraint violations and lost connections are never mapped. The bundle's ids are random
UUIDs and it has no "only one" unique key, so a unique violation is a real error (`500`). Host routes are never
answered by the bundle.

## Host options

```yaml
nowo_yopass:
    conflict_response:
        status: ~          # 409 (default) or 503; null follows nowo_transaction_doctrine.conflict_response, else 409
        retry_after: ~     # seconds for CONFLICT_001; null follows transaction-doctrine, else 1
        commit_tracking: bundle   # bundle | all | off
```

- `commit_tracking: bundle` (default) registers transaction-doctrine's commit-tracking DBAL middleware on the connection
  of `database.entity_manager` only; `all` on every connection; `off` none (only the failure itself and
  transaction-doctrine's request mark decide). When transaction-doctrine's bundle is registered with
  `commit_detection`, its ledger is reused and the bundle adds no middleware.
- Cross-origin clients of the consume endpoint must list `X-Conflict-Code` in `Access-Control-Expose-Headers`.
- Database lock timeouts are the host's: keep `innodb_lock_wait_timeout` (MySQL / MariaDB) and `lock_timeout`
  (PostgreSQL) short enough that a reveal never waits long.

## Entity managers and worker mode

- The bundle reads and writes through its **private entity manager** (`YopassEntityManager`, the default since 1.19.0)
  on the application's connection. The write unit is `DoctrineTransactionRunner::forEntityManager()` on that private
  manager: after a storage failure only the private manager is cleared (or replaced when closed). The application's
  entity manager is never flushed, cleared, closed or reset.
- With the deprecated `database.private_entity_manager: false` the unit is
  `DoctrineTransactionRunner::forHostEntityManager()`: it never clears or resets an open application manager, replaces
  a closed one through the registry, and throws a `LogicException` when the application left changes pending on its
  manager before the unit.
- A unit started inside a transaction of the application runs in a savepoint and rolls back to it on failure
  (`YopassSavepoint`, the runner's nesting repair), so the application's transaction is never left aborted on
  PostgreSQL.
- The listener, the write unit and the commit ledger keep no request state (FrankenPHP worker mode with kernel reset
  off; the ledger is a monotonic counter read by difference only). See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
- The bundle has no Messenger handler; the purge command runs one attempt and is safe to rerun.

## Tests

- `tests/Integration/Database/ConcurrencyDatabaseTest.php` (group `database`, CI job "Databases on …", MySQL 8.4,
  MariaDB 11.4, PostgreSQL 16 and 17, DBAL 3 and 4): two connections, each with its own application manager and the
  private manager, a 1 s lock timeout per session:
  - two reveals of a one-time share never both get the ciphertext; the blocked one answers `409` `CONFLICT_001`, and its
    retry gets `410` (other reveal committed) or the ciphertext (other reveal rolled back);
  - a failure after the read rolls the read back;
  - a commit earlier in the request turns a conflict into `CONFLICT_002` without `Retry-After`;
  - concurrent extensions never move the expiration back, and a refused extension rolls its raise back;
  - an extension blocked by a reveal answers `CONFLICT_001` and changes nothing;
  - the retention purge deletes only rows older than the cutoff.
- `tests/Unit/EventListener/YopassConflictListenerTest.php`: the mapping of every conflict kind to `CONFLICT_001` /
  `CONFLICT_002`, the shapes, the configured status, and the failures that are not mapped.
- Run locally with `make test-with-db` (starts MySQL, MariaDB and PostgreSQL in the `databases` profile).

## Residual risks

- **MongoDB:** the consume is atomic (`findAndModify`), and `readsLeft` is mapped with `strategy: increment`, so a raise
  is written as `$inc` and commutes with a concurrent consume: an extension never gives back a consumed read. The
  remaining residuals are the unguarded writes of the other fields (last write wins), and there is no access log or
  write unit:
  - Two concurrent raises of `maxReads` from the same loaded state (a double submit, or two owners at once) both apply
    their delta, because `maxReads` is a plain `$set` with no condition on the previous value. Two "1 to 3" raises give
    `readsLeft` +4, i.e. two reads above the configured maximum. Only the owner or a manager can trigger it.
  - Two concurrent expiration extensions: the last write wins, so one of them can move the expiration back.

  Use the Doctrine ORM driver for strict accounting.
- **Custom repositories** (`database.driver: custom`) get no write unit: their own storage decides atomicity.
- **Lost commit outcome:** a connection lost during the commit of a consume leaves its outcome unknown; the answer is a
  `500` (not a conflict), and the read may be spent without a reader. The reader sees `410` on retry.
- **Two owners extending at once:** the later expiration wins whatever the commit order; the losing request answers
  `400` `expiration_not_extended` and changes nothing.
