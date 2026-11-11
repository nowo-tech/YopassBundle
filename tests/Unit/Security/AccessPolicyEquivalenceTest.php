<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use InvalidArgumentException;
use Nowo\YopassBundle\Security\ConfigurableYopassAccessChecker;
use Nowo\YopassBundle\Security\UserRef;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Security\YopassAccessDecider;
use Nowo\YopassBundle\Security\YopassAccessPolicyInterface;
use Nowo\YopassBundle\Tests\Support\Ddd\SecurityUserDto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

use function in_array;
use function restore_error_handler;
use function set_error_handler;

use const E_USER_DEPRECATED;

/**
 * REQ-ARCH-001 rule 3 (1.19.0): the framework-free `YopassAccessPolicyInterface` (`?UserRef`) and the deprecated
 * `YopassAccessCheckerInterface` (`?UserInterface`) give the same decisions; deny by default is kept on every path.
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
    public function testTheDefaultCheckerDecidesTheSameThroughBothPorts(array $granted, array $config): void
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(static fn (string $role): bool => in_array($role, $granted, true));
        $checker = new ConfigurableYopassAccessChecker($security, ...$config);

        $user = $granted === [] ? null : new InMemoryUser('alice', null, $granted);
        $ref  = YopassAccessDecider::userRef($user);
        /** @var YopassAccessCheckerInterface $legacy */
        $legacy = $checker;
        /** @var YopassAccessPolicyInterface $policy */
        $policy  = $checker;
        $decider = new YopassAccessDecider($checker);

        foreach ([[$legacy->canAccess($user), $policy->canAccess($ref), $legacy->canAccess(), $decider->allows('access', null, $user), $decider->allows('access', $user)],
            [$legacy->canCreate($user), $policy->canCreate($ref), $legacy->canCreate(), $decider->allows('create', null, $user), $decider->allows('create', $user)],
            [$legacy->canList($user), $policy->canList($ref), $legacy->canList(), $decider->allows('list', null, $user), $decider->allows('list', $user)],
            [$legacy->canRevoke($user), $policy->canRevoke($ref), $legacy->canRevoke(), $decider->allows('revoke', null, $user), $decider->allows('revoke', $user)],
        ] as $i => $answers) {
            self::assertSame([$answers[0], $answers[0], $answers[0], $answers[0], $answers[0]], $answers, self::FEATURES[$i]);
        }
        if ($granted === []) {
            self::assertFalse($decider->allows('access'), 'Anonymous: denied by default');
        }
        self::assertFalse($decider->allows('delete-everything', $user), 'Unknown feature: denied');
    }

    public function testALegacyCheckerIsCalledExactlyAsBefore(): void
    {
        $user   = new InMemoryUser('alice', null, ['ROLE_USER']);
        $calls  = [];
        $legacy = new class($calls) implements YopassAccessCheckerInterface {
            /** @param list<array{string, UserInterface|null}> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function canAccess(?UserInterface $user = null): bool
            {
                $this->calls[] = ['access', $user];

                return true;
            }

            public function canCreate(?UserInterface $user = null): bool
            {
                $this->calls[] = ['create', $user];

                return true;
            }

            public function canList(?UserInterface $user = null): bool
            {
                $this->calls[] = ['list', $user];

                return false;
            }

            public function canRevoke(?UserInterface $user = null): bool
            {
                $this->calls[] = ['revoke', $user];

                return true;
            }
        };

        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            $deprecations[] = $message;

            return true;
        }, E_USER_DEPRECATED);
        try {
            $decider = new YopassAccessDecider($legacy);
        } finally {
            restore_error_handler();
        }
        self::assertSame(['Since nowo-tech/yopass-bundle 1.19.0: implementing only "YopassAccessCheckerInterface" for "nowo_yopass.security.access_checker" is deprecated, implement "YopassAccessPolicyInterface" instead.'], $deprecations);
        self::assertFalse($decider->usesPolicy());

        self::assertTrue($decider->allows('access', null, $user));
        self::assertTrue($decider->allows('create', $user, $user));
        self::assertFalse($decider->allows('list'));
        self::assertTrue($decider->allows('revoke', $user));
        self::assertFalse($decider->allows('unknown', $user));
        self::assertSame([['access', null], ['create', $user], ['list', null], ['revoke', $user]], $calls);
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
        self::assertTrue($decider->usesPolicy());

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
        self::assertEquals(new UserRef('bob', 'bob', ['ROLE_USER']), YopassAccessDecider::userRef(new InMemoryUser('bob', null, ['ROLE_USER'])));
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

        $this->expectException(InvalidArgumentException::class);
        new UserRef(' ');
    }
}
