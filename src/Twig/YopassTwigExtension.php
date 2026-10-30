<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * 1.x Twig globals of the Yopass Web UI (REQ-UI-001), deprecated since 1.10 and removed in 2.0: the pages are on the
 * UiKit page contract (REQ-UI-003) and read `nowo_ui_layout` / the `nowo_ui_kit` CSS stack instead. The globals stay
 * for host template overrides written for 1.x.
 */
final class YopassTwigExtension extends AbstractExtension implements GlobalsInterface
{
    /** Value of `nowo_yopass_layout_template` when `web_ui.layout_template` is null. */
    public const DEFAULT_LAYOUT = '@NowoUiKitBundle/layout/admin.html.twig';

    /**
     * Creates the extension with the configured (or default) layout, CSS framework and icon set.
     */
    public function __construct(
        private readonly ?string $layoutTemplate = null,
        private readonly ?string $cssFramework = null,
        private readonly ?string $iconSet = null,
    ) {
    }

    /**
     * Returns the deprecated 1.x globals: layout template, CSS framework and icon set.
     */
    public function getGlobals(): array
    {
        return [
            'nowo_yopass_layout_template' => $this->layoutTemplate ?? self::DEFAULT_LAYOUT,
            'nowo_yopass_css_framework'   => $this->cssFramework ?? 'tabler',
            'nowo_yopass_icon_set'        => $this->iconSet ?? 'tabler-icons',
        ];
    }
}
