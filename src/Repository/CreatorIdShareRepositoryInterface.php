<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Nowo\YopassBundle\Entity\SecureShare;

/**
 * Creator queries by plain id (1.18.0), next to the object-typed ones of {@see ShareRepositoryInterface}: for hosts
 * that only know the user id (a "user deleted" handler, a GDPR erasure, a read model). Implemented by the ORM and
 * MongoDB repositories; a custom repository may implement it too.
 */
interface CreatorIdShareRepositoryInterface
{
    /**
     * Returns all shares created by the user with this id, newest first.
     *
     * @return list<SecureShare>
     */
    public function findByCreatorId(string $creatorId): array;

    /**
     * Counts the shares created by the user with this id.
     */
    public function countByCreatorId(string $creatorId): int;

    /**
     * Deletes every share of the user with this id (no events; use ShareDeleter for the audited path) and returns the
     * number removed. With the id mapping there is no `ON DELETE CASCADE`: call it when the user is deleted.
     */
    public function removeAllByCreatorId(string $creatorId): int;
}
