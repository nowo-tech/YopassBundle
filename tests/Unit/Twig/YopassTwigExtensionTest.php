<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Twig;

use Nowo\YopassBundle\Twig\YopassTwigExtension;
use PHPUnit\Framework\TestCase;

final class YopassTwigExtensionTest extends TestCase
{
    public function testGetGlobals(): void
    {
        $extension = new YopassTwigExtension(
            '@NowoYopassBundle/layout.html.twig',
            'bootstrap5',
            'bootstrap-icons',
        );

        self::assertSame([
            'nowo_yopass_layout_template' => '@NowoYopassBundle/layout.html.twig',
            'nowo_yopass_css_framework'   => 'bootstrap5',
            'nowo_yopass_icon_set'        => 'bootstrap-icons',
        ], $extension->getGlobals());
    }
}
