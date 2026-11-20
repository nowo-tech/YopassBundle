<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;

/**
 * The entity manager of a bundle repository, resolved per call.
 *
 * The bundle always builds its repositories with its own entity manager (`$ownManager`, the only mode since 2.0): every
 * call goes through that private manager, replaced when closed, so a long-running worker recovers without the
 * kernel.reset services resetter, and the application's manager is never flushed. A repository constructed by hand
 * without it works on the manager it was given.
 *
 * Requires the using class to declare `$entityManager` and `?YopassEntityManager $ownManager`.
 *
 * @internal
 */
trait ResolvesEntityManagerTrait
{
    private function em(): EntityManagerInterface
    {
        return $this->ownManager instanceof YopassEntityManager ? $this->ownManager->get() : $this->entityManager;
    }
}
