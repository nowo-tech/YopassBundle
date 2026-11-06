<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use MessageFormatter;
use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Symfony\Component\Translation\TranslatableMessage;

use function class_exists;
use function preg_match;
use function strtolower;

/**
 * Label of the manage list's retention notice. `shares.retention.max_age` is a PHP relative modifier, not a text for
 * people: the common forms (`14 days`, `+1 month`) become a translated duration in `%max_age%` (ICU plurals of the
 * `NowoYopassBundle+intl-icu` catalogues); any other modifier (`1 month 2 days`, `fortnight`) gets the notice without
 * a duration rather than a half-translated sentence, and so does a PHP without `MessageFormatter`. Display only: the
 * purge keeps reading the modifier itself ({@see ShareRetentionPurger}).
 */
final class RetentionNotice
{
    /** `<N> <unit>`, singular or plural, optional `+`. `\d{1,9}` keeps the count an int on every platform. */
    private const MODIFIER = '/^\s*\+?\s*(\d{1,9})\s*(minute|hour|day|week|month|year)s?\s*$/iD';

    /**
     * @param bool|null $icu whether ICU messages can be formatted (null = detect `MessageFormatter`)
     */
    public static function label(string $maxAge, ?bool $icu = null): Label
    {
        if (!($icu ?? class_exists(MessageFormatter::class)) || preg_match(self::MODIFIER, $maxAge, $match) !== 1) {
            return YopassPages::label('yopass.list.retention_notice_unspecified');
        }

        return YopassPages::label('yopass.list.retention_notice', [
            '%max_age%' => new TranslatableMessage(
                'yopass.list.retention_duration.' . strtolower($match[2]),
                ['count' => (int) $match[1]],
                YopassPages::DOMAIN,
            ),
        ]);
    }
}
