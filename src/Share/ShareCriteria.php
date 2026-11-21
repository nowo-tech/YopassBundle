<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorId;

use function array_values;
use function in_array;
use function is_string;
use function preg_match;
use function strlen;

/**
 * What {@see ShareQueryInterface} reads (2.1.0): the shares of some creators and / or a set of share ids, optionally
 * only the active (or only the inactive) ones, sorted by creation date.
 *
 * At least one of `creatorIds` / `shareIds` is required: there is no "every share" read. With both, a share must match
 * both (the creators' shares among the ids) unless `matchAny` is true (the creators' shares plus the listed ids). Ids
 * are compared exactly (byte for byte, after the database lookup), so a look-alike id a database collation folds
 * (`AbC` for `abc`, a trailing space) never matches.
 *
 * Creator ids follow {@see CreatorId::isUsable()}; share ids are 1–64 characters of `A-Z a-z 0-9 . _ : -`. Anything
 * else is refused with an InvalidArgumentException (never trimmed or normalised). Duplicates are dropped.
 */
final readonly class ShareCriteria
{
    /** Every share, whatever its state. */
    public const STATE_ANY = 'any';

    /** Only shares that can still be read (not revoked, not expired, reads left). */
    public const STATE_ACTIVE = 'active';

    /** Only revoked, expired or used-up shares. */
    public const STATE_INACTIVE = 'inactive';

    /** Longest accepted share id. */
    public const SHARE_ID_MAX_LENGTH = 64;

    /** @var list<string>|null */
    public ?array $creatorIds;

    /** @var list<string>|null */
    public ?array $shareIds;

    /**
     * @param list<string>|null $creatorIds the creators whose shares are read (null: no creator restriction)
     * @param list<string>|null $shareIds the share ids to read (null: no id restriction)
     * @param bool $matchAny with both lists: true = union, false (default) = intersection
     * @param string $state which shares to keep: one of the STATE_* constants
     * @param bool $newestFirst sort by creation date, newest first (default), or oldest first; ties by id
     *
     * @throws InvalidArgumentException when both lists are null, an id is unusable or the state is unknown
     */
    public function __construct(
        ?array $creatorIds = null,
        ?array $shareIds = null,
        public bool $matchAny = false,
        public string $state = self::STATE_ANY,
        public bool $newestFirst = true,
    ) {
        if ($creatorIds === null && $shareIds === null) {
            throw new InvalidArgumentException('A share query needs creator ids or share ids.');
        }
        if (!in_array($state, [self::STATE_ANY, self::STATE_ACTIVE, self::STATE_INACTIVE], true)) {
            throw new InvalidArgumentException('Unknown share state filter.');
        }

        $this->creatorIds = $creatorIds === null ? null : $this->unique($creatorIds, static fn (string $id): bool => CreatorId::isUsable($id), 'creator');
        $this->shareIds   = $shareIds === null ? null : $this->unique($shareIds, self::isUsableShareId(...), 'share');
    }

    /**
     * The shares of one creator.
     *
     * @param self::STATE_* $state
     */
    public static function ofCreator(string $creatorId, string $state = self::STATE_ANY): self
    {
        return new self([$creatorId], null, false, $state);
    }

    /**
     * The shares with these ids.
     *
     * @param list<string> $shareIds
     * @param self::STATE_* $state
     */
    public static function withIds(array $shareIds, string $state = self::STATE_ANY): self
    {
        return new self(null, $shareIds, false, $state);
    }

    /**
     * Whether `$id` is accepted as a share id: 1–64 characters of `A-Z a-z 0-9 . _ : -`.
     */
    public static function isUsableShareId(string $id): bool
    {
        return $id !== '' && strlen($id) <= self::SHARE_ID_MAX_LENGTH && preg_match('/^[A-Za-z0-9._:-]+$/D', $id) === 1;
    }

    /**
     * Whether a share with these attributes matches the creator / id restriction (exact comparison).
     */
    public function matchesIdentity(string $shareId, ?string $creatorId): bool
    {
        $byCreator = $this->creatorIds !== null && $creatorId !== null && in_array($creatorId, $this->creatorIds, true);
        $byId      = $this->shareIds !== null && in_array($shareId, $this->shareIds, true);

        if ($this->creatorIds !== null && $this->shareIds !== null) {
            return $this->matchAny ? $byCreator || $byId : $byCreator && $byId;
        }

        return $this->creatorIds !== null ? $byCreator : $byId;
    }

    /**
     * Whether a share in this state is kept.
     *
     * @param ShareView::STATE_* $shareState
     */
    public function matchesState(string $shareState): bool
    {
        return match ($this->state) {
            self::STATE_ACTIVE   => $shareState === ShareView::STATE_ACTIVE,
            self::STATE_INACTIVE => $shareState !== ShareView::STATE_ACTIVE,
            default              => true,
        };
    }

    /**
     * @param array<mixed> $ids
     * @param callable(string): bool $usable
     *
     * @return list<string>
     */
    private function unique(array $ids, callable $usable, string $kind): array
    {
        $unique = [];
        foreach ($ids as $id) {
            if (!is_string($id) || !$usable($id)) {
                throw new InvalidArgumentException('Unusable ' . $kind . ' id in a share query.');
            }
            // Keyed with a prefix: a numeric-string key ("5") would otherwise become an int.
            $unique['k' . $id] = $id;
        }

        return array_values($unique);
    }
}
