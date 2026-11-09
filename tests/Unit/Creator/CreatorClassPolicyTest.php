<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Creator;

use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardUser;
use PHPUnit\Framework\TestCase;
use stdClass;
use Stringable;

/**
 * Accepted security-user classes and usable ids (audit JX-Y1).
 */
final class CreatorClassPolicyTest extends TestCase
{
    public function testClasses(): void
    {
        $default = new CreatorClassPolicy('\\' . GuardUser::class);
        self::assertTrue($default->isAccepted(new GuardUser(1, 'a')));
        self::assertFalse($default->isAccepted(new SecurityUserDto(1)));
        self::assertNull($default->idOf(new SecurityUserDto(1)));
        self::assertSame(1, $default->idOf(new GuardUser(1, 'a')));

        $listed = new CreatorClassPolicy(GuardUser::class, ['\\' . SecurityUserDto::class]);
        self::assertTrue($listed->isAccepted(new SecurityUserDto(1)));
        self::assertFalse($listed->isAccepted(new TestUser('x')));
        self::assertSame('7', $listed->idOf(new SecurityUserDto('7')));
        self::assertNull($listed->idOf(new GuardUser(0, 'zero')));
    }

    public function testUsableIds(): void
    {
        foreach ([1, '5', 'acc-7', '0195f2c0-uuid'] as $id) {
            self::assertTrue(CreatorClassPolicy::isUsableId($id), (string) $id);
        }
        foreach ([0, -1, '', ' ', '0', '-2', '0.0', null, 1.5, [1], new stdClass()] as $id) {
            self::assertFalse(CreatorClassPolicy::isUsableId($id));
        }
        self::assertTrue(CreatorClassPolicy::isUsableId(new class implements Stringable {
            public function __toString(): string
            {
                return 'uuid';
            }
        }));
    }
}
