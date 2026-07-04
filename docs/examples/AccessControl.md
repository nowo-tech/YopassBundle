# Share listing and per-share access events

The bundle stores **who created each share** (`creator`) but does **not** implement teams, roles, or ACLs itself. Use Symfony events to adapt listing and per-share checks to your application.

Route-level features (`create`, `list`, `revoke`) still go through `YopassAccessCheckerInterface`. Events control **which shares appear** in the manage list and **who may open/preview/extend/revoke/delete** a given share.

## Events

| Event | Constant | When | Use case |
|-------|----------|------|----------|
| `ShareListQueryEvent` | `YopassEvents::SHARE_LIST_QUERY` | Before repository query | Team list, custom query, full override |
| `ShareListResultEvent` | `YopassEvents::SHARE_LIST_RESULT` | After shares loaded | Filter/reorder results |
| `ShareAccessCheckEvent` | `YopassEvents::SHARE_ACCESS_CHECK` | Per share + action | Team access, individual grants, role rules |

### ShareListQueryEvent

Default: query shares where `listSubject` is the logged-in user (creator).

```php
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: YopassEvents::SHARE_LIST_QUERY)]
final class MyShareListListener
{
    public function __invoke(ShareListQueryEvent $event): void
    {
        // Option A: change the creator subject used by the repository
        // $event->setListSubject($teamLeadUser);

        // Option B: replace the query entirely
        // $event->overrideList($shares, $totalCount);
    }
}
```

### ShareListResultEvent

```php
use Nowo\YopassBundle\Event\ShareListResultEvent;
use Nowo\YopassBundle\Event\YopassEvents;

#[AsEventListener(event: YopassEvents::SHARE_LIST_RESULT)]
final class MyShareListFilterListener
{
    public function __invoke(ShareListResultEvent $event): void
    {
        $filtered = array_values(array_filter(
            $event->getShares(),
            fn ($share) => /* your rule */,
        ));
        $event->setShares($filtered);
        $event->setTotal(count($filtered));
    }
}
```

### ShareAccessCheckEvent

Default grant: viewer is the share creator. Listeners may `grant()` or `deny()`.

Actions (`ShareAccessAction`):

| Action | Manage route |
|--------|----------------|
| `View` | Created confirmation page |
| `Preview` | Preview JSON |
| `Extend` | Extend expiration/reads |
| `Revoke` | Revoke share |
| `Delete` | Delete share |

```php
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\YopassEvents;

#[AsEventListener(event: YopassEvents::SHARE_ACCESS_CHECK)]
final class MyShareAccessListener
{
    public function __invoke(ShareAccessCheckEvent $event): void
    {
        if ($event->isGranted()) {
            return;
        }

        if ($this->userInSameTeam($event->getUser(), $event->getShare())) {
            if ($event->getAction() === ShareAccessAction::Delete) {
                return; // keep default deny
            }
            $event->grant();
        }
    }
}
```

## Example implementations

Copy from [`examples/access-control/`](../../examples/access-control/):

| File | Pattern |
|------|---------|
| `TeamShareListListener.php` | List all shares from team members |
| `TeamShareAccessListener.php` | Grant access to same-team shares |
| `IndividualShareGrantListener.php` | Per-share collaborator grants |
| `RoleBasedShareAccessListener.php` | Role limits (e.g. auditor = preview only) |

## Layering with YopassAccessCheckerInterface

| Layer | Scope | Example |
|-------|-------|---------|
| `YopassAccessCheckerInterface` | Can user open manage UI / create / list / revoke at all? | `ROLE_YOPASS_USER` |
| `ShareListQueryEvent` / `ShareListResultEvent` | Which shares appear in the list? | Team members |
| `ShareAccessCheckEvent` | Can user act on this share? | Creator + team lead + grant table |

## Pagination note

When using `overrideList()`, supply the **full** result set and total count. For large teams, implement pagination inside your listener and set `overrideList()` with the current page slice.

`delete all` still removes only shares created by the current user (`removeAllByCreator`). Extend that behaviour in your app if team admins need bulk delete.

## Related

- [Configuration](../CONFIGURATION.md) — `YopassAccessCheckerInterface`
- [Security](../SECURITY.md) — E2E encryption and route firewalls
