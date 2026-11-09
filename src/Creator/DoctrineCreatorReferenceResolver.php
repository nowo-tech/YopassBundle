<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use Doctrine\Persistence\ManagerRegistry;
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
 * entity / document manager (no query); any other user is refused (AccessDeniedException). Without a manager (custom
 * driver) the user is returned unchanged.
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
    ) {
    }

    /**
     * The user itself when it is a `user_class` instance (or without a Doctrine manager: custom driver); a reference
     * of `user_class` with its `getId()` for an instance of an `accepted_user_classes` entry with a usable id.
     *
     * @throws AccessDeniedException for any other user (fail closed, audit JX-Y1)
     */
    public function reference(object $user): object
    {
        $class = ltrim($this->userClass, '\\');
        if ($user instanceof $class || !$this->registry instanceof ManagerRegistry) {
            return $user;
        }

        // JX-Y1: only listed wrapper classes are mapped by id. Any other user fails closed: passing it on would let
        // Doctrine bind another entity class by its id (Admin#5 matching Customer#5). The raw getId() keeps the
        // identifier type of user_class.
        $id = ($this->classes ?? new CreatorClassPolicy($class))->idOf($user);
        if ($id === null) {
            throw new AccessDeniedException(sprintf('nowo_yopass: a %s is not a share creator (not nowo_yopass.user_class nor listed in nowo_yopass.accepted_user_classes with a usable getId()).', get_debug_type($user)));
        }

        $manager = $this->registry->getManager($this->managerName);
        if (!method_exists($manager, 'getReference')) {
            return $user;
        }

        $reference = $manager->getReference($class, $id);

        return is_object($reference) ? $reference : $user;
    }

    /**
     * `getId()` of the user as a string.
     */
    public function idOf(object $user): ?string
    {
        return UserIdResolver::getId($user);
    }
}
