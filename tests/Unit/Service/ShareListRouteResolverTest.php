<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Service;

use InvalidArgumentException;
use Nowo\YopassBundle\Event\ShareListRouteEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Service\ShareListRouteResolver;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Ui\ListRoute;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function base64_encode;
use function explode;
use function hash_hmac;
use function json_encode;
use function rtrim;
use function str_repeat;
use function strtr;

/**
 * REQ-SEC-004: the list page of the post-action redirects is a route name + scalar parameters, signed for the viewer
 * and allow-listed; never a free URL. Placeholder secret only.
 */
final class ShareListRouteResolverTest extends TestCase
{
    private const SECRET = 'placeholder-kernel-secret';

    public function testDefaultsToTheManageRouteWithoutToken(): void
    {
        $resolver = $this->resolver();
        $viewer   = new TestUser('alice');

        $route = $resolver->resolve($viewer, new Request());
        self::assertSame('nowo_yopass_index', $route->name);
        self::assertSame([], $route->params);
        self::assertNull($resolver->sign($viewer, $route), 'the manage route needs no token');
        self::assertSame('nowo_yopass_index', $resolver->fromToken($viewer, null)->name);
    }

    public function testHostRouteRoundTripsForTheSameViewer(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_ROUTE, static function (ShareListRouteEvent $event): void {
            self::assertSame('alice', $event->getViewer()->getUserIdentifier());
            self::assertSame('7', $event->getRequest()->attributes->get('project'));
            self::assertSame('nowo_yopass_index', $event->getRoute()->name);
            $event->setRoute('app_project_secrets', ['id' => '7', 'tab' => 'secrets', 'page' => 2, 'flag' => true]);
        });

        $resolver = $this->resolver($dispatcher);
        $viewer   = new InMemoryUser('alice', null);
        $route    = $resolver->resolve($viewer, new Request(attributes: ['project' => '7']));
        $token    = $resolver->sign($viewer, $route);

        self::assertNotNull($token);
        $back = $resolver->fromToken($viewer, $token);
        self::assertSame('app_project_secrets', $back->name);
        self::assertSame(['id' => '7', 'tab' => 'secrets', 'page' => 2, 'flag' => true], $back->params);
    }

    public function testATokenOfAnotherViewerIsRejected(): void
    {
        $resolver = $this->resolver();
        $token    = $resolver->sign(new TestUser('alice'), new ListRoute('app_project_secrets', ['id' => '7']));

        self::assertSame('nowo_yopass_index', $resolver->fromToken(new InMemoryUser('bob', null), $token)->name);
        self::assertSame('app_project_secrets', $resolver->fromToken(new TestUser('alice'), $token)->name);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function forgedTokens(): iterable
    {
        yield 'not a string' => [['x']];
        yield 'empty' => [''];
        yield 'no signature' => ['abc'];
        yield 'three parts' => ['a.b.c'];
        yield 'too long' => [str_repeat('a', 3000) . '.x'];
        yield 'free URL' => ['https://evil.example/x'];
    }

    #[DataProvider('forgedTokens')]
    public function testForgedTokensFallBackToTheManageRoute(mixed $token): void
    {
        self::assertSame('nowo_yopass_index', $this->resolver()->fromToken(new TestUser('alice'), $token)->name);
    }

    public function testTamperedPayloadOrSignatureIsRejected(): void
    {
        $resolver              = $this->resolver();
        $viewer                = new TestUser('alice');
        $token                 = (string) $resolver->sign($viewer, new ListRoute('app_project_secrets', ['id' => '7']));
        [$payload, $signature] = explode('.', $token);

        $otherPayload = $this->b64((string) json_encode(['app_project_secrets', ['id' => '8']]));
        self::assertSame('nowo_yopass_index', $resolver->fromToken($viewer, $otherPayload . '.' . $signature)->name);
        self::assertSame('nowo_yopass_index', $resolver->fromToken($viewer, $payload . '.' . strtr($signature, 'ABC', 'XYZ') . 'x')->name);
    }

    public function testSignedButNotAllowedOrMalformedPayloadsAreRejected(): void
    {
        // Signed with the same secret by a resolver whose allow-list contains more routes (e.g. config changed).
        $wide   = new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets', 'app_admin'], self::SECRET);
        $narrow = $this->resolver();
        $viewer = new TestUser('alice');

        self::assertSame('nowo_yopass_index', $narrow->fromToken($viewer, $wide->sign($viewer, new ListRoute('app_admin')))->name);

        foreach ([
            '"just a string"',
            '["app_project_secrets"]',
            '[1,{}]',
            '["app_project_secrets","x"]',
            '["app_project_secrets",{"id":{"nested":1}}]',
            '["app_project_secrets",["list"]]',
            'not json',
        ] as $json) {
            $payload = $this->b64($json);
            self::assertSame('nowo_yopass_index', $narrow->fromToken($viewer, $payload . '.' . $this->signature($viewer->getUserIdentifier(), $payload))->name, $json);
        }
    }

    public function testNoSecretDisablesTokens(): void
    {
        $resolver = new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets']);
        $viewer   = new TestUser('alice');

        self::assertNull($resolver->sign($viewer, new ListRoute('app_project_secrets', ['id' => '7'])));
        self::assertSame('nowo_yopass_index', $resolver->fromToken($viewer, 'a.b')->name);
    }

    public function testTheEventRejectsUnlistedRoutesAndNonScalarParams(): void
    {
        $event = new ShareListRouteEvent(new TestUser(), new Request(), new ListRoute('nowo_yopass_index'), ['nowo_yopass_index', 'app_project_secrets']);

        try {
            $event->setRoute('app_admin');
            self::fail('Unlisted route accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        foreach ([['id' => ['x']], [0 => 'x'], ['id' => null]] as $params) {
            try {
                $event->setRoute('app_project_secrets', $params);
                self::fail('Non-scalar parameters accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame('nowo_yopass_index', $event->getRoute()->name);
    }

    private function resolver(?EventDispatcher $dispatcher = null): ShareListRouteResolver
    {
        return new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets', '', 'nowo_yopass_index'], self::SECRET, $dispatcher);
    }

    private function signature(string $viewer, string $payload): string
    {
        return $this->b64(hash_hmac('sha256', 'nowo_yopass.list_route|' . $viewer . '|' . $payload, self::SECRET, true));
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
