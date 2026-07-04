<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

/**
 * Event names dispatched by YopassBundle for share listing and per-share access checks.
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
}
