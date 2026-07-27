<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use Nowo\YopassBundle\Ui\CssFramework;
use Nowo\YopassBundle\Ui\IconSet;
use PHPUnit\Framework\TestCase;

use function count;

final class UiEnumsTest extends TestCase
{
    public function testCssFrameworkValuesAndCdnHelpers(): void
    {
        self::assertContains('tabler', CssFramework::values());
        self::assertContains('bootstrap5', CssFramework::values());
        self::assertTrue(CssFramework::Tabler->loadsBootstrapCompatibleCdn());
        self::assertTrue(CssFramework::Bootstrap5->loadsBootstrapCompatibleCdn());
        self::assertFalse(CssFramework::Tailwind->loadsBootstrapCompatibleCdn());
        self::assertTrue(CssFramework::Bootstrap4->loadsBootstrap4Cdn());
        self::assertFalse(CssFramework::Bootstrap5->loadsBootstrap4Cdn());
    }

    public function testIconSetValues(): void
    {
        self::assertContains('tabler-icons', IconSet::values());
        self::assertContains('bootstrap-icons', IconSet::values());
        self::assertContains('none', IconSet::values());
        self::assertSame(count(IconSet::cases()), count(IconSet::values()));
    }
}
