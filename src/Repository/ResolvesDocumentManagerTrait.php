<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Resolves the DocumentManager per call and replaces it when a previous flush closed it,
 * so a long-running worker recovers without relying on the kernel.reset services resetter.
 *
 * Requires the using class to declare `$documentManager`, `?ManagerRegistry $registry` and `?string $managerName`.
 *
 * @internal
 */
trait ResolvesDocumentManagerTrait
{
    private function dm(): DocumentManager
    {
        if (!$this->registry instanceof ManagerRegistry) {
            return $this->documentManager;
        }

        $manager = $this->registry->getManager($this->managerName);
        if ($manager instanceof DocumentManager && !$manager->isOpen()) {
            $manager = $this->registry->resetManager($this->managerName);
        }

        return $manager instanceof DocumentManager ? $manager : $this->documentManager;
    }
}
