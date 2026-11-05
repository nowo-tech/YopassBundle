<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Support;

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
}
