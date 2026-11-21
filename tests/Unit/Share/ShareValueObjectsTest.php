<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Share;

use DateTimeImmutable;
use InvalidArgumentException;
use Nowo\YopassBundle\Creator\CreatorId;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Share\ShareCommandException;
use Nowo\YopassBundle\Share\ShareCriteria;
use Nowo\YopassBundle\Share\SharePage;
use Nowo\YopassBundle\Share\ShareView;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function print_r;
use function str_repeat;

/**
 * 2.1.0: the read-model value objects of the id-based ports.
 */
final class ShareValueObjectsTest extends TestCase
{
    public function testCriteriaNeedCreatorsOrShareIds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ShareCriteria();
    }

    public function testCriteriaRefuseAnUnknownState(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ShareCriteria(['alice'], null, false, 'pending');
    }

    /**
     * @return iterable<string, array{0: list<mixed>|null, 1: list<mixed>|null}>
     */
    public static function unusableIds(): iterable
    {
        yield 'empty creator'          => [[''], null];
        yield 'padded creator'         => [['bob '], null];
        yield 'zero-width creator'     => [["bob\u{200B}"], null];
        yield 'non-string creator'     => [[5], null];
        yield 'empty share id'         => [null, ['']];
        yield 'share id with a space'  => [null, ['a b']];
        yield 'share id too long'      => [null, [str_repeat('a', 65)]];
        yield 'share id with a quote'  => [null, ["a'"]];
    }

    /**
     * @param list<mixed>|null $creators
     * @param list<mixed>|null $shares
     */
    #[DataProvider('unusableIds')]
    public function testCriteriaRefuseUnusableIds(?array $creators, ?array $shares): void
    {
        $this->expectException(InvalidArgumentException::class);
        // @phpstan-ignore argument.type (unusable input on purpose)
        new ShareCriteria($creators, $shares);
    }

    public function testCriteriaDropDuplicatesAndKeepNumericIdsAsStrings(): void
    {
        $criteria = new ShareCriteria(['5', '5', '05'], ['x-1', 'x-1']);

        self::assertSame(['5', '05'], $criteria->creatorIds);
        self::assertSame(['x-1'], $criteria->shareIds);
        self::assertSame(['7'], ShareCriteria::ofCreator('7')->creatorIds);
        self::assertSame(['a', 'b'], ShareCriteria::withIds(['a', 'b'], ShareCriteria::STATE_ACTIVE)->shareIds);
        self::assertTrue(ShareCriteria::isUsableShareId('00000000-0000-4000-8000-000000000001'));
        self::assertFalse(ShareCriteria::isUsableShareId("x\n"));
    }

    public function testCriteriaMatchIdentityExactly(): void
    {
        $byCreator = ShareCriteria::ofCreator('abc');
        self::assertTrue($byCreator->matchesIdentity('s1', 'abc'));
        self::assertFalse($byCreator->matchesIdentity('s1', 'AbC'));
        self::assertFalse($byCreator->matchesIdentity('s1', null));

        $byId = ShareCriteria::withIds(['s1']);
        self::assertTrue($byId->matchesIdentity('s1', null));
        self::assertFalse($byId->matchesIdentity('S1', 'abc'));

        $both = new ShareCriteria(['abc'], ['s1']);
        self::assertTrue($both->matchesIdentity('s1', 'abc'));
        self::assertFalse($both->matchesIdentity('s2', 'abc'));
        self::assertFalse($both->matchesIdentity('s1', 'bob'));

        $any = new ShareCriteria(['abc'], ['s1'], true);
        self::assertTrue($any->matchesIdentity('s2', 'abc'));
        self::assertTrue($any->matchesIdentity('s1', 'bob'));
        self::assertFalse($any->matchesIdentity('s2', 'bob'));
    }

    public function testCriteriaMatchStates(): void
    {
        $any      = ShareCriteria::ofCreator('a');
        $active   = ShareCriteria::ofCreator('a', ShareCriteria::STATE_ACTIVE);
        $inactive = ShareCriteria::ofCreator('a', ShareCriteria::STATE_INACTIVE);

        foreach ([ShareView::STATE_ACTIVE, ShareView::STATE_EXPIRED, ShareView::STATE_REVOKED, ShareView::STATE_CONSUMED] as $state) {
            self::assertTrue($any->matchesState($state));
            self::assertSame($state === ShareView::STATE_ACTIVE, $active->matchesState($state));
            self::assertSame($state !== ShareView::STATE_ACTIVE, $inactive->matchesState($state));
        }
    }

    public function testTheViewStateAndItsCopyOfAShareWithoutTheCiphertext(): void
    {
        $now     = new DateTimeImmutable('2026-11-21 12:00:00');
        $later   = $now->modify('+1 hour');
        $earlier = $now->modify('-1 hour');

        self::assertSame(ShareView::STATE_ACTIVE, (new ShareView('s', 'a', $earlier, $later, 1, 1, 'text', null, $now))->state);
        self::assertTrue((new ShareView('s', 'a', $earlier, $later, 1, 1, 'text', null, $now))->isActive());
        self::assertSame(ShareView::STATE_EXPIRED, (new ShareView('s', 'a', $earlier, $now, 1, 1, 'text', null, $now))->state);
        self::assertSame(ShareView::STATE_CONSUMED, (new ShareView('s', 'a', $earlier, $later, 1, 0, 'text', null, $now))->state);
        self::assertSame(ShareView::STATE_REVOKED, (new ShareView('s', 'a', $earlier, $later, 1, 1, 'text', $earlier, $now))->state);

        $share = (new SecureShare('00000000-0000-4000-8000-000000000001', new CreatorId('alice')))
            ->setCiphertext('secret-ciphertext')
            ->setExpiresAt($later)
            ->setMaxReads(3)
            ->setPayloadKind('file');
        $view = ShareView::fromShare($share, $now);
        self::assertSame('00000000-0000-4000-8000-000000000001', $view->id);
        self::assertSame('alice', $view->creatorId);
        self::assertSame(3, $view->maxReads);
        self::assertSame('file', $view->payloadKind);
        self::assertStringNotContainsString('secret-ciphertext', print_r($view, true));

        $page = new SharePage([$view], 4, 1, 2);
        self::assertSame(['00000000-0000-4000-8000-000000000001'], $page->ids());
        self::assertSame(4, $page->total);
    }

    public function testTheCommandExceptionCarriesOnlyItsCode(): void
    {
        $exception = new ShareCommandException(ShareCommandException::CIPHERTEXT_TOO_LARGE);

        self::assertSame('ciphertext_too_large', $exception->errorCode);
        self::assertSame('ciphertext_too_large', $exception->getMessage());
        self::assertInstanceOf(InvalidArgumentException::class, $exception);
    }
}
