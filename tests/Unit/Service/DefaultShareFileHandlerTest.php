<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use Nowo\YopassBundle\Service\DefaultShareFileHandler;
use PHPUnit\Framework\TestCase;

final class DefaultShareFileHandlerTest extends TestCase
{
    public function testMaxFileBytes(): void
    {
        self::assertSame(512 * 1024, (new DefaultShareFileHandler())->getMaxFileBytes());
    }
}
