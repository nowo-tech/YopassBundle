<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Event\ShareBulkDeletedEvent;
use Nowo\YopassBundle\Exception\ShareExtendException;

/**
 * Write port over the bundle's shares, keyed by ids (2.1.0, DDD-host checklist B1): what an application handler calls
 * instead of the entity- and user-typed services. Every write dispatches the same lifecycle events as the manage UI
 * (metadata only, after the commit, see docs/USAGE.md). Service id: this interface.
 *
 * No access check is made here: the application checks first (see {@see ShareAccessGuardInterface}). `$actorId` is the
 * id of the user acting, for the events (null: unknown / system).
 */
interface ShareCommandsInterface
{
    /**
     * Stores a share whose ciphertext the client already encrypted (zero-knowledge: the server never sees a key).
     * The same rules as the create form apply: non-empty ciphertext of at most `max_ciphertext_bytes`, an expiration
     * id and a max-reads value from the configured options, payload kind `text` or (with a file handler) `file`. The
     * creator's shares older than the retention age are purged first, as in the manage UI.
     *
     * @throws ShareCommandException when the input is refused
     */
    public function create(CreatorId $creator, ShareCreateData $data): ShareView;

    /**
     * Revokes the share (no read is served any more). Revoking a revoked share changes nothing and dispatches no event.
     *
     * With the Doctrine ORM driver the revoke is one conditional write (2.1.1): of two concurrent revokes of the same
     * share only one writes and dispatches the event, and a share deleted meanwhile answers null with no event. With
     * the MongoDB ODM driver or a custom repository two revokes that both loaded the share before either wrote may both
     * dispatch the event (docs/CONCURRENCY.md, residual risks).
     *
     * @return ShareView|null the share after the call, null when there is no share with this id (or it was deleted
     *                        meanwhile)
     */
    public function revoke(string $shareId, ?string $actorId = null): ?ShareView;

    /**
     * Extends the expiration (an expiration option id) and / or raises the max reads (a configured value).
     * With the Doctrine ORM driver a share deleted meanwhile is refused with `not_found` and dispatches no event (2.1.1).
     *
     * @throws ShareExtendException with code `not_found`, `revoked`, `invalid_expiration`, `invalid_max_reads`,
     *                              `max_reads_not_increased`, `expiration_not_extended` or `nothing_to_extend`
     */
    public function extend(string $shareId, ?string $expiresIn, ?int $maxReads, ?string $actorId = null): ShareView;

    /**
     * Deletes one share. With the Doctrine ORM driver the delete is one conditional statement: of two concurrent deletes
     * of the same share only one returns true and dispatches the event. With the MongoDB ODM driver or a custom
     * repository both may return true and dispatch the event when they loaded the share before either removed it
     * (docs/CONCURRENCY.md, residual risks).
     *
     * @return bool whether this call deleted it (false: no share with this id)
     */
    public function delete(string $shareId, ?string $actorId = null): bool;

    /**
     * Deletes the shares with these ids, only those of `$creator` when it is given (a scoped "delete all"), and
     * dispatches one {@see ShareBulkDeletedEvent} (reason `delete_by_ids`, with the ids) when any was removed.
     *
     * @param list<string> $shareIds
     *
     * @return int the number removed
     */
    public function deleteByIds(array $shareIds, ?CreatorId $creator = null, ?string $actorId = null): int;

    /**
     * Deletes every share of the creator (an account deletion, a GDPR erasure) and dispatches one
     * {@see ShareBulkDeletedEvent} (reason `delete_all`) when any was removed.
     *
     * @return int the number removed
     */
    public function deleteAllOf(CreatorId $creator, ?string $actorId = null): int;
}
