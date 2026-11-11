<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use Stringable;

use function is_int;
use function is_numeric;
use function is_string;
use function ltrim;
use function method_exists;
use function strlen;
use function trim;

/**
 * Which security users the bundle maps to a creator by their `getId()` (1.16, audit JX-Y1).
 *
 * `nowo_yopass.user_class` instances always are. Another class is only when listed in
 * `nowo_yopass.accepted_user_classes` (a wrapper / read model carrying the `user_class` id). With the default empty
 * list, a user of another identity domain (Admin#5 next to Customer#5) is never mapped onto a creator and is never the
 * owner of a share: those paths fail closed. Ids of 0, negative ids and empty ids are refused.
 */
final readonly class CreatorClassPolicy
{
    /** @var list<string> */
    private array $acceptedUserClasses;

    /**
     * @param string $userClass `nowo_yopass.user_class`
     * @param list<string> $acceptedUserClasses `nowo_yopass.accepted_user_classes`
     */
    public function __construct(
        private string $userClass,
        array $acceptedUserClasses = [],
    ) {
        $classes = [];
        foreach ($acceptedUserClasses as $class) {
            $classes[] = ltrim($class, '\\');
        }
        $this->acceptedUserClasses = $classes;
    }

    /**
     * Whether the user is a `user_class` instance.
     */
    public function isCreatorClass(object $user): bool
    {
        $class = ltrim($this->userClass, '\\');

        return $user instanceof $class;
    }

    /**
     * Whether the user is a `user_class` instance, a {@see CreatorId} or an instance of a listed accepted class.
     */
    public function isAccepted(object $user): bool
    {
        // 1.18.0: a CreatorId is what a share returns as its creator with the id mapping.
        if ($user instanceof CreatorId || $this->isCreatorClass($user)) {
            return true;
        }

        foreach ($this->acceptedUserClasses as $class) {
            if ($user instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * The raw `getId()` of an accepted user when it is a usable id (positive int, non-empty string or Stringable that
     * is not a numeric value ≤ 0), else null.
     */
    public function idOf(object $user): int|string|Stringable|null
    {
        if (!$this->isAccepted($user) || !method_exists($user, 'getId')) {
            return null;
        }

        $id = $user->getId();

        return self::isUsableId($id) ? $id : null;
    }

    /**
     * Whether an id value can identify a user: a positive int, or a non-empty string / Stringable of at most 128 bytes,
     * without leading or trailing whitespace, that is not a numeric value ≤ 0.
     */
    public static function isUsableId(mixed $id): bool
    {
        if (is_int($id)) {
            return $id > 0;
        }
        if (!is_string($id) && !$id instanceof Stringable) {
            return false;
        }
        $value = (string) $id;

        // 1.18.0 (LQ-Y-I1): ids are stored and compared byte for byte: never trimmed nor truncated.
        return $value !== ''
            && trim($value) === $value
            && strlen($value) <= CreatorMapping::ID_LENGTH
            && (!is_numeric($value) || (float) $value > 0);
    }
}
