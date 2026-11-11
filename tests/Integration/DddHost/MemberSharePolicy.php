<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Integration\DddHost;

use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;

/**
 * DDD host access policy: framework-free, decides on the user id only (deny by default).
 */
final class MemberSharePolicy implements YopassAccessPolicyInterface
{
    /** @var list<string|null> ids seen, for the test */
    public array $seen = [];

    public function canAccess(?UserRef $user = null): bool
    {
        return $this->member($user);
    }

    public function canCreate(?UserRef $user = null): bool
    {
        return $this->member($user);
    }

    public function canList(?UserRef $user = null): bool
    {
        return $this->member($user);
    }

    public function canRevoke(?UserRef $user = null): bool
    {
        return false;
    }

    private function member(?UserRef $user): bool
    {
        $this->seen[] = $user?->id;

        return $user?->id === '7';
    }
}
