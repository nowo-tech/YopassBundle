<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Support;

use LogicException;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function class_exists;
use function get_debug_type;
use function interface_exists;
use function sprintf;

/**
 * Guards the subject an event listener hands to the creator queries of {@see ShareRepositoryInterface}
 * (`removeAllByCreator()`, `findByCreator*()`, `countByCreator()`).
 *
 * Those queries compare `creator = :subject`; Doctrine binds any managed entity by its identifier without checking
 * its class, so a tenant / team entity whose id equals a user id would select (or delete) that user's shares. Only the
 * viewer itself or an instance of the configured creator class (`nowo_yopass.user_class`; `UserInterface` when the
 * class is unknown) is accepted. Any other scope must be resolved by the host listener itself
 * (`ShareDeleteAllEvent::markHandled()`, `ShareListQueryEvent::overrideList()`).
 */
final class CreatorSubjectGuard
{
    /**
     * Whether the subject may be passed to the creator queries.
     */
    public static function isSupported(object $subject, UserInterface $viewer, ?string $creatorClass): bool
    {
        if ($subject === $viewer) {
            return true;
        }

        if ($creatorClass !== null && $creatorClass !== '' && (class_exists($creatorClass) || interface_exists($creatorClass))) {
            return $subject instanceof $creatorClass;
        }

        return $subject instanceof UserInterface;
    }

    /**
     * Throws when the subject may not be passed to the creator queries (fails closed: nothing is queried or deleted).
     *
     * @throws LogicException
     */
    public static function assertSupported(object $subject, UserInterface $viewer, ?string $creatorClass, string $hint): void
    {
        if (self::isSupported($subject, $viewer, $creatorClass)) {
            return;
        }

        throw new LogicException(sprintf('nowo_yopass: a listener set a %s subject; the creator queries only accept the viewer or a %s (another entity would be matched by its id against creator ids). %s', get_debug_type($subject), $creatorClass !== null && $creatorClass !== '' ? $creatorClass : UserInterface::class, $hint));
    }
}
