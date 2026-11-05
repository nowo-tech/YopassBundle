<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use Nowo\YopassBundle\Event\ShareListRouteEvent;

/**
 * Route (name + scalar parameters) of the page that shows the manage list: the bundle's manage route by default, or a
 * host page (e.g. a project's "Secrets" tab) set through {@see ShareListRouteEvent}.
 */
final readonly class ListRoute
{
    /**
     * @param array<string, scalar> $params
     */
    public function __construct(
        public string $name,
        public array $params = [],
    ) {
    }
}
