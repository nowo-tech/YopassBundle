<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Support;

use DateTimeImmutable;
use Nowo\YopassBundle\Support\RelativeInterval;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RelativeIntervalTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int|null}>
     */
    public static function intervals(): iterable
    {
        yield 'hour' => ['1 hour', 3600];
        yield 'days, leading plus' => ['+7 days', 604800];
        yield 'empty' => ['', null];
        yield 'only plus and spaces' => ['+ ', null];
        // PHP 8.2: warning + false; PHP >= 8.3: DateMalformedStringException — never thrown from here (FO-I3).
        yield 'malformed' => ['bogus', null];
        yield 'backwards' => ['-1 hour', null];
        yield 'no move' => ['0 seconds', null];
    }

    #[DataProvider('intervals')]
    public function testSeconds(string $interval, ?int $expected): void
    {
        self::assertSame($expected, RelativeInterval::seconds($interval));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function compoundModifiers(): iterable
    {
        yield 'two terms' => ['1 month 2 days', '-1 month -2 days'];
        yield 'plus on both' => ['+1 month +2 days', '-1 month -2 days'];
        yield 'plus on the first' => ['+1 month 2 days', '-1 month -2 days'];
        yield 'plus on the second' => ['1 month +2 days', '-1 month -2 days'];
        yield 'signed second term' => ['1 month -2 days', '-1 month +2 days'];
        yield 'trailing line break' => ["1 month 2 days\n", '-1 month -2 days'];
        yield 'line break between terms' => ["1 month\n2 days", '-1 month -2 days'];
        yield 'windows line break' => ["1 month\r\n2 days", '-1 month -2 days'];
        yield 'no-break space' => ["1 month\xC2\xA02 days", '-1 month -2 days'];
        yield 'second line' => ["14 days\n2 days", '-14 days -2 days'];
        yield 'full unit names' => ['5 seconds 5 minutes', '-5 seconds -5 minutes'];
        yield 'three terms' => ['1 year 2 weeks 3 hours', '-1 year -2 weeks -3 hours'];
        yield 'no spaces' => ['1month2days', '-1 month -2 days'];
        yield 'comma' => ['1 month, 2 days', '-1 month -2 days'];
        yield 'padded' => [' 1 month  2 days ', '-1 month -2 days'];
        yield 'upper case' => ['1 MONTH 2 Days', '-1 MONTH -2 Days'];
    }

    #[DataProvider('compoundModifiers')]
    public function testNegatedNegatesEveryTerm(string $modifier, string $expected): void
    {
        self::assertSame($expected, RelativeInterval::negated($modifier));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function singleTerms(): iterable
    {
        yield 'plain' => ['14 days'];
        yield 'plus' => ['+14 days'];
        yield 'plus, space' => ['+ 14 days'];
        yield 'minus' => ['-14 days'];
        yield 'singular' => ['1 month'];
        yield 'plural one' => ['1 years'];
        yield 'fortnight' => ['1 fortnight'];
        yield 'weekdays' => ['3 weekdays'];
        yield 'short unit' => ['30 min'];
        yield 'padded' => [' 14 days '];
        yield 'ago, not a term' => ['14 days ago'];
    }

    #[DataProvider('singleTerms')]
    public function testSingleTermsGiveTheSameDateAsALeadingMinus(string $modifier): void
    {
        $now = new DateTimeImmutable('2026-11-06 12:00:00');

        self::assertEquals($now->modify('-' . $modifier), $now->modify(RelativeInterval::negated($modifier)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherModifiers(): iterable
    {
        yield 'words' => ['first day of next month'];
        yield 'ago' => ['1 month 2 days ago'];
        yield 'time of day' => ['14 days 2 pm'];
        yield 'text term' => ['1 month next week'];
        yield 'unknown unit' => ['1 month 2 bogus'];
        yield 'empty' => [''];
    }

    #[DataProvider('otherModifiers')]
    public function testAnythingElseKeepsTheLeadingMinus(string $modifier): void
    {
        self::assertSame('-' . $modifier, RelativeInterval::negated($modifier));
    }

    public function testRetentionCutoffIsInThePastOrNull(): void
    {
        $now = new DateTimeImmutable('2026-11-06 12:00:00');

        self::assertSame('2026-10-23', RelativeInterval::retentionCutoff($now, '14 days')?->format('Y-m-d'));
        self::assertSame('2026-10-04', RelativeInterval::retentionCutoff($now, '+1 month +2 days')?->format('Y-m-d'));
        self::assertNull(RelativeInterval::retentionCutoff($now, '-14 days'));
        self::assertNull(RelativeInterval::retentionCutoff($now, '1 month ago'));
        self::assertNull(RelativeInterval::retentionCutoff($now, '0 days'));
        self::assertNull(RelativeInterval::retentionCutoff($now, 'bogus'));
    }

    public function testRetentionCutoffInTheDeepPastIsNull(): void
    {
        $now = new DateTimeImmutable('2026-11-06 12:00:00');

        self::assertNull(RelativeInterval::retentionCutoff($now, '100000 years'));
        self::assertNull(RelativeInterval::retentionCutoff($now, '99999999999 days'));
        self::assertNull(RelativeInterval::retentionCutoff($now, '2030-01-01'));
        self::assertSame('1026-11-06', RelativeInterval::retentionCutoff($now, '1000 years')?->format('Y-m-d'));
    }

    public function testIsPlainTerms(): void
    {
        foreach (['14 days', '+1 month +2 days', "1 month\n2 days", '1 month, 2 days'] as $plain) {
            self::assertTrue(RelativeInterval::isPlainTerms($plain), $plain);
        }

        foreach (['0 days midnight', '14 days yesterday', '1 month next week', '1 month ago', ''] as $other) {
            self::assertFalse(RelativeInterval::isPlainTerms($other), $other);
        }
    }
}
