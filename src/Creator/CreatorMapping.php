<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

/**
 * Values of `nowo_yopass.user_mapping` (1.18.0): how a share stores its creator.
 */
final class CreatorMapping
{
    /** Doctrine relation (ORM `ManyToOne`, ODM `ReferenceOne`) to `user_class`. The default. */
    public const RELATION = 'relation';

    /** The creator id in a string column / field: no relation, no foreign key, `user_class` need not be mapped. */
    public const ID = 'id';

    /** Length of the creator id column with the id mapping (ints, UUIDs, ULIDs and other string ids). */
    public const ID_LENGTH = 128;

    /**
     * Every supported value.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return [self::RELATION, self::ID];
    }
}
