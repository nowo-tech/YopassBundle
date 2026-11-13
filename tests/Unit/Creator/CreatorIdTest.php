<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Creator;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorClassPolicy;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Creator\CreatorMapping;
use Nowo\YopassBundle\Creator\DoctrineCreatorReferenceResolver;
use Nowo\YopassBundle\Document\SecureShareDocument;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use Nowo\YopassBundle\Tests\Support\Orm\GuardAdmin;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

use function str_repeat;

/**
 * 1.18.0 id mapping: the CreatorId value object, the resolver in id mode and shares holding a plain creator id.
 */
final class CreatorIdTest extends TestCase
{
    public function testValueObject(): void
    {
        $id = new CreatorId('c-1');

        self::assertSame('c-1', $id->getId());
        self::assertSame('c-1', (string) $id);
        self::assertEquals($id, CreatorId::wrap('c-1'));
        $user = new SecurityUserDto(3);
        self::assertSame($user, CreatorId::wrap($user));
        self::assertSame('c-1', CreatorId::unwrap($id));
        self::assertSame($user, CreatorId::unwrap($user));
        self::assertSame('c-1', CreatorId::idOf('c-1'));
        self::assertSame('3', CreatorId::idOf($user));
    }

    public function testRefusesAnEmptyId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CreatorId('');
    }

    public function testRefusesAnOverlongId(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new CreatorId(str_repeat('a', 129));
    }

    /** @return iterable<string, array{0: string}> */
    public static function unusableIds(): iterable
    {
        // REQ-SEC-006 (1.20.4): look-alikes of `bob` that a collation or a trim would fold onto it
        yield 'trailing space' => ['bob '];
        yield 'leading space' => [' bob'];
        yield 'newline' => ["bob\n"];
        yield 'tab' => ["bob\t"];
        yield 'NBSP' => ["bob\u{00A0}"];
        yield 'ZWSP' => ["bob\u{200B}"];
        yield 'inner ZWSP' => ["b\u{200B}ob"];
        yield 'BOM' => ["\u{FEFF}bob"];
        yield 'bidi mark' => ["bob\u{200E}"];
        yield 'inner space' => ['bo b'];
        yield 'invalid UTF-8' => ["bob\xFF"];
        yield 'NUL' => ["bob\0"];
    }

    #[DataProvider('unusableIds')]
    public function testRefusesIdsThatAreNotExactKeys(string $id): void
    {
        self::assertFalse(CreatorId::isUsable($id));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('without spaces, control or invisible characters');
        new CreatorId($id);
    }

    public function testCaseAndAccentVariantsAreOtherIdsNotRefusedOnes(): void
    {
        foreach (['Bob', 'bób', 'bob', 'b-ob_1@example.com', str_repeat('a', 128)] as $id) {
            self::assertTrue(CreatorId::isUsable($id), $id);
            self::assertSame($id, (new CreatorId($id))->getId());
        }
        self::assertFalse(CreatorId::isUsable(''));
        self::assertFalse(CreatorId::isUsable(str_repeat('a', 129)));
    }

    public function testALegacyStoredIdStaysAsStoredButNoRequestCanNameIt(): void
    {
        $legacy = CreatorId::wrap('bob ');

        self::assertInstanceOf(CreatorId::class, $legacy);
        self::assertSame('bob ', $legacy->getId());
        self::assertNotSame('bob', $legacy->getId());
    }

    public function testTheResolverRefusesAnUnusableIdOfAnAcceptedUser(): void
    {
        $resolver = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, null, CreatorMapping::ID);

        $this->expectException(AccessDeniedException::class);
        $resolver->reference(new SecurityUserDto("bob\u{00A0}"));
    }

    public function testPolicyRefusesPaddedAndOverlongIds(): void
    {
        self::assertFalse(CreatorClassPolicy::isUsableId(' abc'));
        self::assertFalse(CreatorClassPolicy::isUsableId('abc '));
        self::assertFalse(CreatorClassPolicy::isUsableId(str_repeat('a', 129)));
        self::assertTrue(CreatorClassPolicy::isUsableId(str_repeat('a', 128)));
    }

    public function testPolicyAcceptsCreatorIds(): void
    {
        $policy = new CreatorClassPolicy(TestUser::class);

        self::assertTrue($policy->isAccepted(new CreatorId('9')));
        self::assertSame('9', $policy->idOf(new CreatorId('9')));
    }

    public function testResolverInIdModeReturnsCreatorIds(): void
    {
        $resolver = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, null, CreatorMapping::ID);
        $id       = new CreatorId('a-1');

        self::assertSame($id, $resolver->reference($id));
        self::assertEquals(new CreatorId('42'), $resolver->reference(new SecurityUserDto(42)));

        $this->expectException(AccessDeniedException::class);
        $resolver->reference(new GuardAdmin(42));
    }

    public function testResolverInIdModeRefusesUnusableIds(): void
    {
        $resolver = new DoctrineCreatorReferenceResolver(SecurityUserDto::class, null, null, new CreatorClassPolicy(SecurityUserDto::class), CreatorMapping::ID);

        $this->expectException(AccessDeniedException::class);
        $resolver->reference(new SecurityUserDto(0));
    }

    public function testSharesStoreThePlainIdAndExposeACreatorId(): void
    {
        $share = (new SecureShare('s-1', new CreatorId('u-7')))->setExpiresAt(new DateTimeImmutable('+1 hour'));
        self::assertEquals(new CreatorId('u-7'), $share->getCreator());
        self::assertSame('u-7', $share->getCreatorId());

        $fromString = new SecureShare('s-2', 'u-8');
        self::assertSame('u-8', $fromString->getCreatorId());

        $document = SecureShareDocument::fromShare($share);
        self::assertEquals(new CreatorId('u-7'), $document->getCreator());
        self::assertSame('u-7', $document->toShare()->getCreatorId());
        self::assertSame('u-9', (new SecureShareDocument('s-3', 'u-9'))->setExpiresAt(new DateTimeImmutable('+1 hour'))->toShare()->getCreatorId());
    }
}
