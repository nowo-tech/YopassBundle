<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

/**
 * Manage-route action checked via {@see ShareAccessCheckEvent}.
 */
enum ShareAccessAction: string
{
    case View    = 'view';
    case Preview = 'preview';
    case Extend  = 'extend';
    case Revoke  = 'revoke';
    case Delete  = 'delete';
}
