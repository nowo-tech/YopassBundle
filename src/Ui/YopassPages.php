<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use Nowo\UiKitBundle\View\Label;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Throwable;
use Twig\Environment;

use function is_string;
use function trigger_deprecation;

/**
 * Page plumbing of the UiKit page contract (REQ-UI-003): resolves the layouts the pages extend
 * (`web_ui.layout_template`, null = `nowo_ui_kit.layouts.admin`; `web_ui.public_layout_template`, null =
 * `nowo_ui_kit.layouts.guest`), wraps a 1.x layout (no UiKit canonical blocks) in a deprecated bridge, and resolves
 * the per-page template overrides (`web_ui.templates.<page>`). Configuration only: no request state, safe in worker
 * mode.
 */
final readonly class YopassPages
{
    /** Bundle name of the page view models (theme exceptions `nowo_ui_kit.theme.bundles.NowoYopassBundle`). */
    public const BUNDLE = 'NowoYopassBundle';

    /** Translation domain of the bundle labels. */
    public const DOMAIN = YopassBundle::TRANSLATION_DOMAIN;

    /** BC bridge around a 1.x layout that renders `{% block body %}` (removed in 2.0). */
    public const LEGACY_BRIDGE = '@NowoYopassBundle/_legacy_layout.html.twig';

    /** Page ids accepted by `web_ui.templates` (page id => default template). */
    public const TEMPLATES = [
        'manage'  => '@NowoYopassBundle/manage/index.html.twig',
        'created' => '@NowoYopassBundle/manage/created.html.twig',
        'public'  => '@NowoYopassBundle/public/reveal.html.twig',
    ];

    /** A layout that defines this block renders the UiKit canonical blocks. */
    private const CANONICAL_BLOCK = 'nowo_ui_page_header';

    /**
     * Canonical asset blocks a 1.x layout may already render inside its `stylesheets` / `javascripts` blocks (the
     * bridge stacks onto those with parent() and skips them, so they are not printed twice). The 1.x `body` block is
     * replaced by the bridge, so blocks rendered there need no detection.
     */
    private const LEGACY_HOST_BLOCKS = ['nowo_ui_styles', 'nowo_ui_scripts'];

    /**
     * Creates the page plumbing from the resolved `web_ui` settings.
     *
     * @param array<string, string|null> $templates `web_ui.templates` (page id => template, null = bundle template)
     */
    public function __construct(
        #[Autowire('%nowo_yopass.web_ui.layout_template%')]
        private ?string $layoutTemplate = null,
        #[Autowire('%nowo_yopass.web_ui.public_layout_template%')]
        private ?string $publicLayoutTemplate = null,
        #[Autowire('%nowo_yopass.web_ui.templates%')]
        private array $templates = [],
        private ?Environment $twig = null,
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
     * Layout the manage scaffolds extend (`page.layout`): `web_ui.layout_template`, the legacy bridge for a 1.x
     * layout, else null (UiKit `nowo_ui_layout.admin`).
     */
    public function layout(): ?string
    {
        if ($this->layoutTemplate === null || $this->layoutTemplate === '') {
            return null;
        }

        return $this->legacy($this->layoutTemplate) !== null ? self::LEGACY_BRIDGE : $this->layoutTemplate;
    }

    /**
     * Layout of the public reveal page: `web_ui.public_layout_template`, else null (UiKit `nowo_ui_layout.guest`).
     */
    public function publicLayout(): ?string
    {
        return $this->publicLayoutTemplate === '' ? null : $this->publicLayoutTemplate;
    }

    /**
     * Shared variables of the manage pages (added to the controller variables): the 1.x layout wrapped by the
     * deprecated bridge and the canonical blocks it already renders.
     *
     * @param array<string, mixed> $vars page variables
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        $legacy = $this->layoutTemplate !== null && $this->layoutTemplate !== '' ? $this->legacy($this->layoutTemplate) : null;

        return $vars + [
            'nowo_yopass_legacy_layout' => $legacy,
            'nowo_yopass_legacy_blocks' => $this->hostBlocks($legacy),
        ];
    }

    /**
     * Canonical blocks a 1.x layout defines itself (the bridge does not render them a second time).
     *
     * @return list<string>
     */
    private function hostBlocks(?string $legacy): array
    {
        if ($legacy === null || !$this->twig instanceof Environment) {
            return [];
        }

        $template = $this->twig->load($legacy);
        $context  = $this->twig->mergeGlobals([]);
        $blocks   = [];
        foreach (self::LEGACY_HOST_BLOCKS as $block) {
            if ($template->hasBlock($block, $context)) {
                $blocks[] = $block;
            }
        }

        return $blocks;
    }

    /**
     * The configured layout when it is a 1.x layout (it does not define the UiKit canonical blocks), else null.
     */
    private function legacy(string $layout): ?string
    {
        if (!$this->twig instanceof Environment) {
            return null;
        }

        try {
            $canonical = $this->twig->load($layout)->hasBlock(self::CANONICAL_BLOCK, $this->twig->mergeGlobals([]));
        } catch (Throwable) {
            return null;
        }

        if ($canonical) {
            return null;
        }

        trigger_deprecation('nowo-tech/yopass-bundle', '1.10', 'The layout "%s" does not render the UiKit canonical blocks (nowo_ui_page_header, nowo_ui_content, ...); it is wrapped in a deprecated bridge. Render the canonical blocks (or extend @NowoUiKit/layout/admin.html.twig) or set nowo_yopass.web_ui.layout_template to null to use nowo_ui_kit.layouts.admin; see docs/UPGRADING.md.', $layout);

        return $layout;
    }
}
