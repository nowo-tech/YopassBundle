<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Twig;

use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;

/**
 * Twig globals for the Yopass manage Web UI (REQ-UI-001).
 */
final class YopassTwigExtension extends AbstractExtension implements GlobalsInterface
{
    public function __construct(
        private readonly string $layoutTemplate,
        private readonly string $cssFramework,
        private readonly string $iconSet,
    ) {
    }

    public function getGlobals(): array
    {
        return [
            'nowo_yopass_layout_template' => $this->layoutTemplate,
            'nowo_yopass_css_framework'   => $this->cssFramework,
            'nowo_yopass_icon_set'        => $this->iconSet,
        ];
    }
}
