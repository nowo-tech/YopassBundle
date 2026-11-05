<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use InvalidArgumentException;
use Nowo\YopassBundle\Ui\ListRoute;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

use function in_array;
use function is_scalar;
use function is_string;
use function sprintf;

/**
 * Dispatched when the manage list is rendered ({@see YopassEvents::SHARE_LIST_ROUTE}): a host that shows the list on
 * its own page (e.g. `/projects/{id}/secrets`, forwarding to the list controller) sets that page's route here. The
 * pager, the filter form and the redirects after revoke / delete / delete all then use it.
 *
 * The route must be listed in `nowo_yopass.web_ui.list_routes` (or be the manage route) and its parameters must be
 * scalars: the bundle never accepts a free URL. Action forms carry the route as a token signed for the viewer, which
 * the action handlers verify against the same allow-list before redirecting.
 */
final class ShareListRouteEvent extends Event
{
    /**
     * @param list<string> $allowedRoutes Route names a listener may set (the manage route included)
     */
    public function __construct(private readonly UserInterface $viewer, private readonly Request $request, private ListRoute $route, private readonly array $allowedRoutes)
    {
    }

    /**
     * Returns the user viewing the list.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the request rendering the list (the forwarded sub-request on a host page).
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * Returns the list route (default: the manage route).
     */
    public function getRoute(): ListRoute
    {
        return $this->route;
    }

    /**
     * Sets the host page that shows the list.
     *
     * @param array<mixed> $params Scalar route parameters with string keys only
     *
     * @throws InvalidArgumentException When the route is not allowed or a parameter is not a scalar
     */
    public function setRoute(string $name, array $params = []): void
    {
        if (!in_array($name, $this->allowedRoutes, true)) {
            throw new InvalidArgumentException(sprintf('The list route "%s" is not allowed: add it to nowo_yopass.web_ui.list_routes.', $name));
        }

        $clean = [];
        foreach ($params as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                throw new InvalidArgumentException(sprintf('The list route "%s" only accepts scalar parameters with string keys.', $name));
            }
            $clean[$key] = $value;
        }

        // @igor-ignore - Request-scoped Event DTO; not a shared worker service.
        $this->route = new ListRoute($name, $clean);
    }
}
