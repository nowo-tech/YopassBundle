<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\YopassEntityManager;

/**
 * Resolves the EntityManager per call and replaces it when a previous flush closed it,
 * so a long-running worker recovers without relying on the kernel.reset services resetter.
 *
 * With the bundle's own entity manager (`$ownManager`, 1.19.0, the default of the bundle configuration) every call goes
 * through that private manager and the application's manager is never flushed.
 *
 * Requires the using class to declare `$entityManager`, `?ManagerRegistry $registry`, `?string $managerName` and
 * `?YopassEntityManager $ownManager`.
 *
 * @internal
 */
trait ResolvesEntityManagerTrait
{
    private function em(): EntityManagerInterface
    {
        if ($this->ownManager instanceof YopassEntityManager) {
            return $this->ownManager->get();
        }

        if (!$this->registry instanceof ManagerRegistry) {
            return $this->entityManager;
        }

        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof EntityManagerInterface && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof EntityManagerInterface ? $manager : $this->entityManager;
    }
}
