<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;
use Nowo\YopassBundle\Support\UserIdResolver;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function get_debug_type;
use function is_object;
use function ltrim;
use function method_exists;
use function sprintf;

/**
 * Default creator reference resolver: `user_class` instances unchanged; an instance of a class listed in
 * `nowo_yopass.accepted_user_classes` with a usable `getId()` becomes `getReference(user_class, id)` on the bundle's
 * entity / document manager (no query); any other user is refused (AccessDeniedException), with every driver. With the
 * custom driver (no manager) an accepted user is returned unchanged.
 *
 * With `nowo_yopass.user_mapping: id` (1.18.0) every accepted user (and a {@see CreatorId}) becomes a
 * {@see CreatorId}: the share stores the id, never a relation.
 */
final readonly class DoctrineCreatorReferenceResolver implements CreatorReferenceResolverInterface
{
    /**
     * @param string $userClass `nowo_yopass.user_class`
     * @param ?ManagerRegistry $registry Doctrine ORM or MongoDB ODM registry (null = no reference)
     * @param ?string $managerName Entity / document manager name
     * @param ?CreatorClassPolicy $classes Accepted security-user classes (null = `user_class` only)
     */
    public function __construct(
        private string $userClass,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
        private ?CreatorClassPolicy $classes = null,
        private string $userMapping = CreatorMapping::RELATION,
        private ?YopassEntityManager $ownManager = null,
    ) {
    }

    /**
     * The user itself when it is a `user_class` instance; for an instance of an `accepted_user_classes` entry with a
     * usable id, a reference of `user_class` with its `getId()` (the user itself with the custom driver).
     *
     * @throws AccessDeniedException for any other user (fail closed, audit JX-Y1)
     */
    public function reference(object $user): object
    {
        $class = ltrim($this->userClass, '\\');
        if ($this->userMapping === CreatorMapping::ID) {
            return $this->creatorId($user, $class);
        }

        if ($user instanceof $class) {
            return $this->ownReference($user, $class);
        }

        // JX-Y1: only listed wrapper classes are mapped by id. Any other user fails closed: passing it on would let
        // Doctrine bind another entity class by its id (Admin#5 matching Customer#5). The raw getId() keeps the
        // identifier type of user_class. JX-Y6: the custom driver gets the same check before its users pass through.
        $id = ($this->classes ?? new CreatorClassPolicy($class))->idOf($user);
        if ($id === null) {
            throw new AccessDeniedException(sprintf('nowo_yopass: a %s is not a share creator (not nowo_yopass.user_class nor listed in nowo_yopass.accepted_user_classes with a usable getId()).', get_debug_type($user)));
        }

        if (!$this->registry instanceof ManagerRegistry) {
            // Custom driver: the host repository receives the accepted user as given.
            return $user;
        }

        $manager = $this->ownManager instanceof YopassEntityManager ? $this->ownManager->get() : $this->registry->getManager($this->managerName);
        if (!method_exists($manager, 'getReference')) {
            return $user;
        }

        $reference = $manager->getReference($class, $id);

        return is_object($reference) ? $reference : $user;
    }

    /**
     * With the bundle's own entity manager (1.19.0): a `user_class` entity managed by the application's manager becomes
     * a reference in the private manager (same class and id, no query), so the share's relation never points into
     * another unit of work. Unchanged without the private manager or when it already manages the user.
     */
    private function ownReference(object $user, string $class): object
    {
        if (!$this->ownManager instanceof YopassEntityManager) {
            return $user;
        }

        $manager = $this->ownManager->get();
        if ($manager->contains($user)) {
            return $user;
        }
        /** @var class-string $class */
        $reference = $manager->getReference($class, $manager->getClassMetadata($class)->getIdentifierValues($user));

        return is_object($reference) ? $reference : $user;
    }

    /**
     * Id mapping (1.18.0): the {@see CreatorId} of a `user_class` instance, a CreatorId or an accepted user.
     *
     * @throws AccessDeniedException for any other user, or one without a usable id
     */
    private function creatorId(object $user, string $class): CreatorId
    {
        if ($user instanceof CreatorId) {
            return $user;
        }

        $id = ($this->classes ?? new CreatorClassPolicy($class))->idOf($user);
        // 1.20.4 (REQ-SEC-006): with the id mapping the id is also an exact key (no spaces, controls or invisible
        // characters), refused like any other unusable user instead of failing in the CreatorId constructor
        if ($id === null || !CreatorId::isUsable((string) $id)) {
            throw new AccessDeniedException(sprintf('nowo_yopass: a %s is not a share creator (not nowo_yopass.user_class nor listed in nowo_yopass.accepted_user_classes with a usable getId()).', get_debug_type($user)));
        }

        return new CreatorId((string) $id);
    }

    /**
     * `getId()` of the user as a string.
     */
    public function idOf(object $user): ?string
    {
        return UserIdResolver::getId($user);
    }
}
