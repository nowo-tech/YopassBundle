<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

/**
 * Event names dispatched by YopassBundle: share listing, per-share access checks and share lifecycle.
 *
 * Lifecycle payloads (created / consumed / deleted / bulk deleted) are metadata only: they never carry ciphertext, keys,
 * passwords or decrypted content (zero-knowledge).
 */
final class YopassEvents
{
    /**
     * Fired before the default creator-based list query.
     * Listeners may change the list subject or replace the query entirely.
     */
    public const SHARE_LIST_QUERY = 'nowo_yopass.share_list_query';

    /**
     * Fired after shares are loaded and before they are rendered.
     * Listeners may filter or reorder the result set.
     */
    public const SHARE_LIST_RESULT = 'nowo_yopass.share_list_result';

    /**
     * Fired when checking whether a user may access a specific share in manage routes.
     * Default grant is creator ownership; listeners may grant or deny further access.
     */
    public const SHARE_ACCESS_CHECK = 'nowo_yopass.share_access_check';

    /**
     * Fired after a share is persisted ({@see ShareCreatedEvent}; metadata only, never the ciphertext).
     */
    public const SHARE_CREATED = 'nowo_yopass.share_created';

    /**
     * Fired after a single share is deleted from the manage UI ({@see ShareDeletedEvent}; metadata only).
     */
    public const SHARE_DELETED = 'nowo_yopass.share_deleted';

    /**
     * Fired after a share is revoked from the manage UI ({@see ShareRevokedEvent}; metadata only).
     */
    public const SHARE_REVOKED = 'nowo_yopass.share_revoked';

    /**
     * Fired after one read of a share was consumed through its public link ({@see ShareConsumedEvent}; ids and
     * counters only, no IP address or user agent). Since 1.17.
     */
    public const SHARE_CONSUMED = 'nowo_yopass.share_consumed';

    /**
     * Fired after a share's expiration / max reads were extended ({@see ShareExtendedEvent}; old and new limits only).
     */
    public const SHARE_EXTENDED = 'nowo_yopass.share_extended';

    /**
     * Fired after the manage list is loaded, before rendering ({@see ShareListColumnsEvent}): hosts add extra columns
     * (e.g. "Created by", "Shared with") whose cell values are plain text, escaped by the template.
     */
    public const SHARE_LIST_COLUMNS = 'nowo_yopass.share_list_columns';

    /**
     * Fired when the manage list is rendered ({@see ShareListRouteEvent}): a host page showing the list sets its route
     * (allow-listed in `web_ui.list_routes`, scalar parameters) for the pager, the filters and the action redirects.
     */
    public const SHARE_LIST_ROUTE = 'nowo_yopass.share_list_route';

    /**
     * Fired before the manage list is loaded ({@see ShareListFiltersEvent}): hosts add GET filter fields whose data
     * reaches {@see ShareListQueryEvent::getFilters()}.
     */
    public const SHARE_LIST_FILTERS = 'nowo_yopass.share_list_filters';

    /**
     * Fired before the manage "delete all" action ({@see ShareDeleteAllEvent}).
     * Listeners may scope the removal (tenant / team subject), perform it themselves, or veto it.
     */
    public const SHARE_DELETE_ALL = 'nowo_yopass.share_delete_all';

    /**
     * Fired after shares are removed in bulk ("delete all" or retention purge; {@see ShareBulkDeletedEvent}).
     */
    public const SHARES_BULK_DELETED = 'nowo_yopass.shares_bulk_deleted';
}
