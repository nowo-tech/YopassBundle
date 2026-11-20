<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support\Odm;

use RuntimeException;

/**
 * An application `onClear` listener that throws: the private document manager's clear must never let it escape.
 */
final class ThrowingClearListener
{
    public function onClear(): never
    {
        throw new RuntimeException('onClear failed');
    }
}
