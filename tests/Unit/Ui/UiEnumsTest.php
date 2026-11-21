<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use Nowo\YopassBundle\Ui\CssFramework;
use Nowo\YopassBundle\Ui\IconSet;
use PHPUnit\Framework\TestCase;

use function count;

final class UiEnumsTest extends TestCase
{
    public function testCssFrameworkValues(): void
    {
        self::assertContains('tabler', CssFramework::values());
        self::assertContains('bootstrap5', CssFramework::values());
        self::assertSame(count(CssFramework::cases()), count(CssFramework::values()));
    }

    public function testIconSetValues(): void
    {
        self::assertContains('tabler-icons', IconSet::values());
        self::assertContains('bootstrap-icons', IconSet::values());
        self::assertContains('none', IconSet::values());
        self::assertSame(count(IconSet::cases()), count(IconSet::values()));
    }
}
