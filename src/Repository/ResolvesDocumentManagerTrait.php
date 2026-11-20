<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;

/**
 * Resolves the DocumentManager per call and replaces it when a previous flush closed it,
 * so a long-running worker recovers without relying on the kernel.reset services resetter.
 *
 * With the bundle's own document manager (`$ownManager`, 1.22.0 opt-in `database.private_document_manager: true`, the
 * only mode in 2.0) every call goes through that private manager and the application's manager is never flushed.
 *
 * Requires the using class to declare `$documentManager`, `?ManagerRegistry $registry`, `?string $managerName` and
 * `?YopassDocumentManager $ownManager`.
 *
 * @internal
 */
trait ResolvesDocumentManagerTrait
{
    private function dm(): DocumentManager
    {
        if ($this->ownManager instanceof YopassDocumentManager) {
            return $this->ownManager->get();
        }

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
