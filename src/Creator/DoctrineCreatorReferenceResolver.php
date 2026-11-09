<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Support\UserIdResolver;

use function is_object;
use function ltrim;
use function method_exists;

/**
 * Default creator reference resolver: `user_class` instances unchanged; any other user with a `getId()` becomes
 * `getReference(user_class, id)` on the bundle's entity / document manager (no query). Without a manager (custom
 * driver) or an id, the user is returned unchanged (the 1.15 behaviour).
 */
final readonly class DoctrineCreatorReferenceResolver implements CreatorReferenceResolverInterface
{
    /**
     * @param string $userClass `nowo_yopass.user_class`
     * @param ?ManagerRegistry $registry Doctrine ORM or MongoDB ODM registry (null = no reference)
     * @param ?string $managerName Entity / document manager name
     */
    public function __construct(
        private string $userClass,
        private ?ManagerRegistry $registry = null,
        private ?string $managerName = null,
    ) {
    }

    /**
     * The user itself when it is a `user_class` instance, else a reference of `user_class` with its `getId()`.
     */
    public function reference(object $user): object
    {
        $class = ltrim($this->userClass, '\\');
        if ($user instanceof $class || !$this->registry instanceof ManagerRegistry) {
            return $user;
        }

        // The raw getId() value (int, string, Uuid…), so the reference carries the identifier type of user_class.
        $id = method_exists($user, 'getId') ? $user->getId() : null;
        if ($id === null || $id === '') {
            return $user;
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
