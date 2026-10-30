<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\Ui\YopassPages;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class YopassPagesTest extends TestCase
{
    public function testLabelUsesTheBundleDomain(): void
    {
        $label = YopassPages::label('yopass.title', ['%x%' => 1]);

        self::assertInstanceOf(Label::class, $label);
        self::assertSame('yopass.title', $label->key);
        self::assertSame('NowoYopassBundle', $label->domain);
        self::assertSame(['%x%' => 1], $label->params);
    }

    public function testTemplateResolvesOverridesAndDefaults(): void
    {
        $pages = new YopassPages(templates: ['manage' => 'host/manage.html.twig', 'created' => '', 'public' => null]);

        self::assertSame('host/manage.html.twig', $pages->template('manage'));
        self::assertSame(YopassPages::TEMPLATES['created'], $pages->template('created'));
        self::assertSame(YopassPages::TEMPLATES['public'], $pages->template('public'));
        self::assertSame('unknown', $pages->template('unknown'));
    }

    public function testLayoutsDefaultToTheUiKitLayouts(): void
    {
        $pages = new YopassPages();

        self::assertNull($pages->layout());
        self::assertNull($pages->publicLayout());
        self::assertNull((new YopassPages('', ''))->layout());
        self::assertNull((new YopassPages('', ''))->publicLayout());
        self::assertSame('guest.html.twig', (new YopassPages(publicLayoutTemplate: 'guest.html.twig'))->publicLayout());
        self::assertSame(['page' => 1, 'nowo_yopass_legacy_layout' => null, 'nowo_yopass_legacy_blocks' => []], $pages->context(['page' => 1]));
    }

    public function testCanonicalLayoutIsUsedAsIs(): void
    {
        $twig  = $this->twig(['canonical.html.twig' => '{% block nowo_ui_page_header %}{% endblock %}{% block nowo_ui_content %}{% endblock %}']);
        $pages = new YopassPages('canonical.html.twig', twig: $twig);

        self::assertSame('canonical.html.twig', $pages->layout());
        self::assertNull($pages->context([])['nowo_yopass_legacy_layout']);
    }

    public function testLayoutWithoutTwigOrUnknownTemplateIsUsedAsIs(): void
    {
        self::assertSame('base.html.twig', (new YopassPages('base.html.twig'))->layout());
        self::assertSame('missing.html.twig', (new YopassPages('missing.html.twig', twig: $this->twig([])))->layout());
    }

    #[IgnoreDeprecations]
    public function testLegacyLayoutIsWrappedInTheBridge(): void
    {
        $twig = $this->twig([
            'base.html.twig' => '{% block stylesheets %}{% block nowo_ui_styles %}{% endblock %}{% endblock %}{% block body %}{% endblock %}{% block javascripts %}{% endblock %}',
        ]);
        $pages = new YopassPages('base.html.twig', twig: $twig);

        self::assertSame(YopassPages::LEGACY_BRIDGE, $pages->layout());
        $context = $pages->context(['page' => 1]);
        self::assertSame('base.html.twig', $context['nowo_yopass_legacy_layout']);
        self::assertSame(['nowo_ui_styles'], $context['nowo_yopass_legacy_blocks']);
    }

    /**
     * @param array<string, string> $templates
     */
    private function twig(array $templates): Environment
    {
        return new Environment(new ArrayLoader($templates));
    }
}
