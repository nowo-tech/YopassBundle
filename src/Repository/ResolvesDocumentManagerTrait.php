<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Repository;

use Doctrine\ODM\MongoDB\DocumentManager;
use Nowo\YopassBundle\Doctrine\YopassDocumentManager;

/**
 * The document manager of the bundle's MongoDB repository, resolved per call.
 *
 * The bundle always builds the repository with its own document manager (`$ownManager`, 1.22.0 opt-in, the only mode
 * since 2.0): every call goes through that private manager, replaced when closed, and the application's manager is
 * never flushed. A repository constructed by hand without it works on the manager it was given.
 *
 * Requires the using class to declare `$documentManager` and `?YopassDocumentManager $ownManager`.
 *
 * @internal
 */
trait ResolvesDocumentManagerTrait
{
    private function dm(): DocumentManager
    {
        return $this->ownManager instanceof YopassDocumentManager ? $this->ownManager->get() : $this->documentManager;
    }
}
