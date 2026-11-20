<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;

/**
 * REQ-ARCH-001 rule 3: the framework-free `YopassAccessPolicyInterface` (`?UserRef`, the only access port since 2.0)
 * and the decider in front of it give the same decisions whatever user the controller passes; deny by default is kept
 * on every path.
 */
final class AccessPolicyEquivalenceTest extends TestCase
{
    private const FEATURES = ['access', 'create', 'list', 'revoke'];

    /**
     * @return iterable<string, array{list<string>, array{list<string>, list<string>, list<string>, list<string>, list<string>}}>
     */
    public static function grants(): iterable
    {
        $configs = [
            'defaults'          => [['ROLE_ADMIN'], ['ROLE_USER'], ['ROLE_USER'], ['ROLE_USER'], ['ROLE_USER']],
            'nothing granted'   => [[], [], [], [], []],
            'split roles'       => [['ROLE_ADMIN'], ['ROLE_A'], ['ROLE_C'], ['ROLE_L'], ['ROLE_R']],
            'revoke admin only' => [['ROLE_ADMIN'], ['ROLE_USER'], ['ROLE_USER'], ['ROLE_USER'], []],
        ];
        $tokens = [
            'anonymous'  => [],
            'user'       => ['ROLE_USER'],
            'admin'      => ['ROLE_ADMIN'],
            'list only'  => ['ROLE_A', 'ROLE_L'],
            'all split'  => ['ROLE_A', 'ROLE_C', 'ROLE_L', 'ROLE_R'],
            'other role' => ['ROLE_OTHER'],
        ];
        foreach ($configs as $configName => $config) {
            foreach ($tokens as $tokenName => $granted) {
                yield $configName . ' / ' . $tokenName => [$granted, $config];
            }
        }
    }

    /**
     * @param list<string> $granted roles of the current token
     * @param array{list<string>, list<string>, list<string>, list<string>, list<string>} $config
     */
    #[DataProvider('grants')]
    public function testTheDefaultCheckerDecidesTheSameWhateverUserIsPassed(array $granted, array $config): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, $granted, true));
        $checker = new ConfigurableYopassAccessChecker($security, ...$config);

        $user    = $granted === [] ? null : new InMemoryUser('alice', null, $granted);
        $ref     = YopassAccessDecider::userRef($user);
        $decider = new YopassAccessDecider($checker);

        foreach ([[$checker->canAccess($ref), $checker->canAccess(), $decider->allows('access', null, $user), $decider->allows('access', $user)],
            [$checker->canCreate($ref), $checker->canCreate(), $decider->allows('create', null, $user), $decider->allows('create', $user)],
            [$checker->canList($ref), $checker->canList(), $decider->allows('list', null, $user), $decider->allows('list', $user)],
            [$checker->canRevoke($ref), $checker->canRevoke(), $decider->allows('revoke', null, $user), $decider->allows('revoke', $user)],
        ] as $i => $answers) {
            self::assertSame([$answers[0], $answers[0], $answers[0], $answers[0]], $answers, self::FEATURES[$i]);
        }
        if ($granted === []) {
            self::assertFalse($decider->allows('access'), 'Anonymous: denied by default');
        }
        self::assertFalse($decider->allows('delete-everything', $user), 'Unknown feature: denied');
    }

    public function testAPolicyReceivesTheUserRefOfTheSignedInUser(): void
    {
        $seen   = [];
        $policy = new class($seen) implements YopassAccessPolicyInterface {
            /** @param list<array{string, UserRef|null}> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function canAccess(?UserRef $user = null): bool
            {
                $this->seen[] = ['access', $user];

                return $user instanceof UserRef;
            }

            public function canCreate(?UserRef $user = null): bool
            {
                $this->seen[] = ['create', $user];

                return $user?->id === '42';
            }

            public function canList(?UserRef $user = null): bool
            {
                $this->seen[] = ['list', $user];

                return $user?->hasRole('ROLE_LIST') ?? false;
            }

            public function canRevoke(?UserRef $user = null): bool
            {
                $this->seen[] = ['revoke', $user];

                return false;
            }
        };
        $decider = new YopassAccessDecider($policy);

        $member = new SecurityUserDto(42, 'member@example.com', ['ROLE_LIST']);
        self::assertTrue($decider->allows('access', null, $member));
        self::assertTrue($decider->allows('create', $member));
        self::assertTrue($decider->allows('list', null, $member));
        self::assertFalse($decider->allows('revoke', $member));
        self::assertFalse($decider->allows('access'), 'Nobody signed in: null UserRef');
        self::assertFalse($decider->allows('nope', $member));
        self::assertEquals(new UserRef('42', 'member@example.com', ['ROLE_LIST']), $seen[0][1]);
        self::assertNull($seen[4][1]);
        self::assertCount(5, $seen);

        // Without getId(): the identifier is the id. Without either: denied, never anonymous.
        self::assertEquals(new UserRef('bob', 'bob', ['ROLE_USER'], true), YopassAccessDecider::userRef(new InMemoryUser('bob', null, ['ROLE_USER'])));
        self::assertFalse($seen[0][1]?->idIsIdentifier, 'A real getId() is not flagged');
        $nameless = $this->createStub(UserInterface::class);
        $nameless->method('getUserIdentifier')->willReturn('');
        $nameless->method('getRoles')->willReturn([]);
        self::assertNull(YopassAccessDecider::userRef($nameless));
        self::assertFalse($decider->allows('access', $nameless));
        self::assertCount(5, $seen, 'The policy is not asked for a nameless user');
    }

    public function testUserRef(): void
    {
        $ref = new UserRef('7', 'alice', ['ROLE_A', 'ROLE_B']);
        self::assertSame(['ROLE_A', 'ROLE_B'], $ref->roles);
        self::assertTrue($ref->hasRole('ROLE_B'));
        self::assertFalse($ref->hasRole('ROLE_C'));
        self::assertSame('', (new UserRef('7'))->identifier);
        self::assertFalse((new UserRef('7'))->idIsIdentifier);

        $this->expectException(InvalidArgumentException::class);
        new UserRef(' ');
    }
}
