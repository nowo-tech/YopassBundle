<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use InvalidArgumentException;
use Nowo\YopassBundle\Support\UserIdResolver;
use Stringable;

use function is_string;
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
     * @throws InvalidArgumentException when the id is empty
     */
    public function __construct(
        private string $id,
    ) {
        if (trim($id) === '') {
            throw new InvalidArgumentException('A creator id cannot be empty.');
        }
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
        return is_string($stored) ? new self($stored) : $stored;
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
