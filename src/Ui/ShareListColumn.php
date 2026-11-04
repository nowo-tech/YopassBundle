<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use Closure;
use InvalidArgumentException;
use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareListColumnsEvent;
use Stringable;

use function in_array;
use function is_scalar;
use function preg_match;
use function sprintf;

/**
 * Extra column of the manage list contributed by the host through {@see ShareListColumnsEvent}.
 *
 * The cell value is plain text: the provider returns a scalar, a Stringable or null (rendered as "—"), and the template
 * prints it with Twig auto-escaping (never `|raw`). Providers must not return secret material; the share passed in is
 * the list row (its ciphertext is not meant for display).
 */
final readonly class ShareListColumn
{
    /** Keys of the built-in columns (an extra column may not reuse them). */
    public const RESERVED_KEYS = ['payloadKind', 'createdAt', 'expiresAt', 'readsLeft', 'status'];

    /** @var Closure(SecureShare): mixed */
    private Closure $value;

    /**
     * @param string $key Column key (`[a-zA-Z][a-zA-Z0-9_]*`, not a built-in key)
     * @param Label|string $label Header label (a UiKit Label is translated; a string is printed escaped)
     * @param callable(SecureShare): mixed $value Cell value provider: scalar, Stringable or null (plain text; anything else renders as an empty cell)
     * @param int|null $priority UiKit column priority (2 / 3 hide the column on narrow screens)
     */
    public function __construct(
        public string $key,
        public Label|string $label,
        callable $value,
        public ?int $priority = null,
    ) {
        if (preg_match('/^[a-zA-Z][a-zA-Z0-9_]*$/', $key) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid Yopass list column key "%s".', $key));
        }

        if (in_array($key, self::RESERVED_KEYS, true)) {
            throw new InvalidArgumentException(sprintf('The Yopass list column key "%s" is reserved.', $key));
        }

        $this->value = $value(...);
    }

    /**
     * Cell text for a row (null = empty cell).
     */
    public function valueFor(SecureShare $share): ?string
    {
        $value = ($this->value)($share);

        if ($value === null) {
            return null;
        }

        return is_scalar($value) || $value instanceof Stringable ? (string) $value : null;
    }
}
