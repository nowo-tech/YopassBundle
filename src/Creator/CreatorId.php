<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use InvalidArgumentException;
use ReflectionClass;
use Nowo\YopassBundle\Support\UserIdResolver;
use Stringable;

use function is_string;
use function preg_match;
use function strlen;
use function trim;

/**
 * A share creator known only by its id (1.18.0): what a share returns as its creator when `nowo_yopass.user_mapping`
 * is `id` (the creator column / field holds the id; there is no relation to the host user table or collection).
 *
 * It exposes `getId()` like a host user, so the owner checks, the events and the listings work the same in both
 * mappings.
 */
final readonly class CreatorId implements Stringable
{
    /**
     * Characters a creator id must not contain (REQ-SEC-006, since 1.20.4): controls, format characters (zero-width,
     * bidi, BOM), separators (any space, NBSP included) and the other default-ignorable code points. With `u`, invalid
     * UTF-8 makes preg_match() return false, which refuses the id as well.
     */
    private const UNUSABLE = '/[\p{Cc}\p{Cf}\p{Z}\x{034F}\x{115F}\x{1160}\x{17B4}\x{17B5}\x{180B}-\x{180F}\x{3164}\x{FE00}-\x{FE0F}\x{FFA0}\x{E0100}-\x{E01EF}]/u';

    /**
     * @throws InvalidArgumentException when the id is empty, longer than 128 bytes, or not usable as an exact key
     *                                  ({@see self::isUsable()}; since 1.20.4, never trimmed)
     */
    public function __construct(
        private string $id,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A creator id cannot be empty.');
        }
        if (strlen($id) > CreatorMapping::ID_LENGTH) {
            throw new InvalidArgumentException('A creator id is at most 128 bytes long.');
        }
        if (!self::isUsable($id)) {
            throw new InvalidArgumentException('A creator id must be valid UTF-8 without spaces, control or invisible characters.');
        }
    }

    /**
     * Whether `$id` can be stored and compared byte for byte as a creator id (REQ-SEC-006, since 1.20.4): non-empty,
     * at most 128 bytes, valid UTF-8, no whitespace (edge or inner, NBSP included), no control, format (zero-width,
     * bidi, BOM) or other default-ignorable character. Such ids are refused, never trimmed or normalised: `bob ` or
     * `bob\u{200B}` would otherwise reach `bob`'s shares wherever a collation or a trim folds them.
     */
    public static function isUsable(string $id): bool
    {
        return $id !== '' && strlen($id) <= CreatorMapping::ID_LENGTH && preg_match(self::UNUSABLE, $id) === 0;
    }

    /**
     * The id as stored (the string form of the host user's `getId()`).
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * Same as {@see self::getId()}.
     */
    public function __toString(): string
    {
        return $this->id;
    }

    /**
     * The object a share exposes for its stored creator: a {@see self} for an id (id mapping), the related object
     * unchanged (relation mapping).
     *
     * @internal used by the share entity and document
     */
    public static function wrap(object|string $stored): object
    {
        if (!is_string($stored)) {
            return $stored;
        }
        if (self::isUsable($stored)) {
            return new self($stored);
        }

        // A value stored before 1.20.4 that is no longer a usable id (`bob `): the share keeps it as is, and no request
        // can name it any more, so it is unreachable through the creator lookups (fail closed).
        $legacy     = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $legacy->id = $stored;

        return $legacy;
    }

    /**
     * The value a share stores for a creator: the id string for a {@see self}, the object unchanged otherwise.
     *
     * @internal used by the share entity and document
     */
    public static function unwrap(object $creator): object|string
    {
        return $creator instanceof self ? $creator->id : $creator;
    }

    /**
     * The id of a stored creator: the string itself, or the related object's `getId()`.
     *
     * @internal used by the share entity
     */
    public static function idOf(object|string $stored): ?string
    {
        return is_string($stored) ? $stored : UserIdResolver::getId($stored);
    }
}
