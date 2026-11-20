<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Doctrine;

use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Query\FilterCollection;
use LogicException;

/**
 * The document manager {@see YopassDocumentManager} creates (1.22.0): a plain ODM document manager whose BSON filters
 * ARE the application's (audit XC-M1). {@see getFilterCollection()} returns the source manager's own filter collection,
 * so every query of the private manager (find, refresh, findAndUpdate, query-builder removes) applies exactly the filter
 * instances the application enabled, with their parameters AND any state they keep in properties, at the moment of the
 * query: the scoping is identical to the default mode by construction. Nothing is copied.
 *
 * @internal created by {@see YopassDocumentManager} only
 */
class YopassPrivateDocumentManager extends DocumentManager
{
    private ?DocumentManager $filterSource = null;

    /**
     * The application's document manager whose filters this manager uses (set before the manager is handed out).
     */
    public function useFiltersOf(DocumentManager $source): void
    {
        // @igor-ignore - Bundle-owned manager, dropped with it at every outermost boundary.
        $this->filterSource = $source;
    }

    /**
     * The application's filter collection (never a collection of this manager's own).
     */
    public function getFilterCollection(): FilterCollection
    {
        if (!$this->filterSource instanceof DocumentManager) {
            throw new LogicException('nowo_yopass: the private document manager has no source manager for its filters.');
        }

        return $this->filterSource->getFilterCollection();
    }
}
