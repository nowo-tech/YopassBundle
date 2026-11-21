<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Fixtures\RemovedPort;

use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A 1.x host decorator of the port removed in 2.0, registered by autodiscovery. It cannot be loaded (the interface no
 * longer exists): the container build must fail instead of dropping it silently. Never autoloaded outside
 * AutodiscoveredRemovedPortKernelTest.
 */
#[AsDecorator(YopassAccessCheckerInterface::class)]
final class DecoratingLegacyAccessChecker implements YopassAccessCheckerInterface
{
    public function canAccess(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canCreate(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canList(?UserInterface $user = null): bool
    {
        return true;
    }

    public function canRevoke(?UserInterface $user = null): bool
    {
        return true;
    }
}
