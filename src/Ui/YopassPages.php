<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use LogicException;
use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use Twig\Environment;

use function is_string;
use function sprintf;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layouts the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null =
 * `nowo_ui_kit.layouts.guest`) and the per-page template overrides (`web_ui.templates.<page>`). A configured manage
 * layout must render the UiKit canonical blocks: the 1.x layout bridge was removed in 2.0, so a 1.x layout fails with
 * a clear error instead of rendering an empty page. Configuration only: no request state, safe in worker mode.
 */
final readonly class YopassPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoYopassBundle`). */
    public const BUNDLE = 'NowoYopassBundle';

    /** Translation domain of the bundle labels. */
    public const DOMAIN = YopassBundle::TRANSLATION_DOMAIN;

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'manage'  => '@NowoYopassBundle/manage/index.html.twig',
        'created' => '@NowoYopassBundle/manage/created.html.twig',
        'public'  => '@NowoYopassBundle/public/reveal.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /**
     * Creates the page plumbing from the resolved `web_ui` settings.
     *
     * @param array<string, string|null> $templates `web_ui.templates` (page id => template, null = bundle template)
     * @param array<string, string|null> $icons `web_ui.icons` (create tab icons; null = label only)
     */
    public function __construct(
        #[Autowire('%nowo_yopass.web_ui.layout_template%')]
        private ?string $layoutTemplate = null,
        #[Autowire('%nowo_yopass.web_ui.public_layout_template%')]
        private ?string $publicLayoutTemplate = null,
        #[Autowire('%nowo_yopass.web_ui.templates%')]
        private array $templates = [],
        private ?Environment $twig = null,
        #[Autowire('%nowo_yopass.web_ui.icons%')]
        private array $icons = [],
    ) {
    }

    /**
     * Translation key of the bundle domain as a UiKit label.
     *
     * @param array<string, mixed> $params
     */
    public static function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }

    /**
     * Template of a page: the `web_ui.templates.<page>` override, else the bundle template.
     */
    public function template(string $page): string
    {
        $override = $this->templates[$page] ?? null;

        return is_string($override) && $override !== '' ? $override : self::TEMPLATES[$page] ?? $page;
    }

    /**
     * Layout the manage scaffolds extend (`page.layout`): `web_ui.layout_template`, else null (UiKit
     * `nowo_ui_layout.admin`).
     *
     * @throws LogicException when the configured layout does not render the UiKit canonical blocks (a 1.x layout)
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }
        $this->assertCanonical($this->layoutTemplate);

        return $this->layoutTemplate;
    }

    /**
     * Layout of the public reveal page: `web_ui.public_layout_template`, else null (UiKit `nowo_ui_layout.guest`).
     */
    public function publicLayout(): ?string
    {
        return $this->publicLayoutTemplate === '' ? null : $this->publicLayoutTemplate;
    }

    /**
     * Shared variables of the manage pages (added to the controller variables).
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $vars + [
            'nowo_yopass_icons' => $this->icons,
        ];
    }

    /**
     * Refuses a layout that does not define the UiKit canonical blocks (a 1.x layout): the 1.x bridge that wrapped it
     * was removed in 2.0. A layout Twig cannot load is left to Twig's own error at render time.
     */
    private function assertCanonical(string $layout): void
    {
        if (!$this->twig instanceof Environment) {
            return;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return;
        }

        if (!$canonical) {
            throw new LogicException(sprintf('The layout "%s" (nowo_yopass.web_ui.layout_template) does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...). The 1.x layout bridge was removed in 2.0: render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig), or set the option to null to use nowo_ui_kit.layouts.admin; see UPGRADING "To 2.0.0".', $layout));
        }
    }
}
