<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Fixtures\RemovedPort;

use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.x host checker still on the port removed in 2.0, registered by autodiscovery. It cannot be loaded (the interface
 * no longer exists): the container build must fail instead of dropping it silently. Never autoloaded outside
 * AutodiscoveredRemovedPortKernelTest.
 */
#[AsAlias(YopassAccessCheckerInterface::class)]
final class AliasedLegacyAccessChecker implements YopassAccessCheckerInterface
{
    public function canAccess(?UserInterface $user = null): bool
    {
        return false;
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        return false;
    }

    public function canList(?UserInterface $user = null): bool
    {
        return false;
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        return false;
    }
}
