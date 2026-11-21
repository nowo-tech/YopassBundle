<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Creator;

use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * A {@see CreatorId} that cannot name a `user_class` user (2.1.1, audit YD3-L1): under the relation mapping an integer
 * key only takes a plain decimal id, so `abc` or `07` is refused (never cast). It is an AccessDeniedException as before
 * (fail closed, unchanged for callers that catch that), and its own type lets `ShareCommandsInterface` tell an unusable
 * id (`invalid_creator`, or no shares on `deleteAllOf()`) from a misconfiguration (a composite identifier, an object
 * manager without references), which keeps propagating.
 */
final class UnusableCreatorIdException extends AccessDeniedException
{
}
