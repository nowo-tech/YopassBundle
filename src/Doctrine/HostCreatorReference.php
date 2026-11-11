<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

/**
 * The creator the lifecycle events expose with the bundle's own entity manager (1.19.0, audit MO-Y-L1).
 *
 * With the relation mapping, a share's creator is a reference in the bundle's private entity manager. Events hand the
 * application the **same user in its own manager** instead: `getReference(user_class, id)` on
 * `database.entity_manager`, built lazily from the stored id (no query; the managed instance itself when the
 * application already loaded that user). A listener can therefore relate it to its own entities and flush. A creator
 * the private manager does not manage (a `CreatorId` with `user_mapping: id`, a custom repository's object) is returned
 * unchanged.
 */
final readonly class HostCreatorReference
{
    public function __construct(private YopassEntityManager $ownManager)
    {
    }

    /**
     * The creator as the application's entity manager sees it.
     */
    public function forHost(object $creator): object
    {
        $private = $this->ownManager->get();
        if (!$private->contains($creator)) {
            return $creator;
        }

        $class     = $private->getClassMetadata($creator::class)->getName();
        $reference = $this->ownManager->source()->getReference($class, $private->getUnitOfWork()->getEntityIdentifier($creator));

        return $reference ?? $creator;
    }
}
