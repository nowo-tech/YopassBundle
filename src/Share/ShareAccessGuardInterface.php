<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Security\UserRef;

/**
 * Per-share manage access by ids (2.1.0, DDD-host checklist B8). Service id: this interface.
 */
interface ShareAccessGuardInterface
{
    /**
     * Whether the user may perform the action on the share. Owner by default: the user's id equals the share's creator
     * id, exactly (a {@see UserRef} whose `id` is only its identifier, `idIsIdentifier`, is never the owner). A
     * {@see ShareAccessCheckEvent} listener may grant or deny either way. False for an unknown share.
     *
     * The `UserRef` id is taken as an id of `nowo_yopass.user_class`: the application passes the id of that class.
     */
    public function canManageId(UserRef $user, string $shareId, ShareAccessAction $action): bool;
}
