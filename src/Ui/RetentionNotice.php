<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use MessageFormatter;
use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

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
     * @param TranslatorInterface|null $translator translates the duration here, so a host translator that does not
     *                                             resolve nested translatable parameters still prints it; a key it does
     *                                             not know gives the notice without a duration
     */
    public static function label(string $maxAge, ?bool $icu = null, ?TranslatorInterface $translator = null): Label
    {
        $unspecified = YopassPages::label('yopass.list.retention_notice_unspecified');
        if (!($icu ?? class_exists(MessageFormatter::class)) || preg_match(self::MODIFIER, $maxAge, $match) !== 1) {
            return $unspecified;
        }

        $key    = 'yopass.list.retention_duration.' . strtolower($match[2]);
        $params = ['count' => (int) $match[1]];
        if (!$translator instanceof TranslatorInterface) {
            return YopassPages::label('yopass.list.retention_notice', ['%max_age%' => new TranslatableMessage($key, $params, YopassPages::DOMAIN)]);
        }

        $duration = $translator->trans($key, $params, YopassPages::DOMAIN);

        return $duration === $key ? $unspecified : YopassPages::label('yopass.list.retention_notice', ['%max_age%' => $duration]);
    }
}
