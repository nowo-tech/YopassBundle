<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\ControllerContainerBuilder;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use const JSON_THROW_ON_ERROR;

final class ShareManageControllerTest extends TestCase
{
    /** @var array<string, array{path: string, name: string}> */
    private array $routes;

    /** @var array{layout: string, manage: string, created: string, public: string} */
    private array $templates;

    protected function setUp(): void
    {
        $this->routes = [
            'manage'         => ['path' => '/tools/yopass', 'name' => 'nowo_yopass_index'],
            'create'         => ['path' => '/tools/yopass/create', 'name' => 'nowo_yopass_create'],
            'revoke'         => ['path' => '/tools/yopass/{id}/revoke', 'name' => 'nowo_yopass_revoke'],
            'delete'         => ['path' => '/tools/yopass/{id}/delete', 'name' => 'nowo_yopass_delete'],
            'delete_all'     => ['path' => '/tools/yopass/delete-all', 'name' => 'nowo_yopass_delete_all'],
            'preview'        => ['path' => '/tools/yopass/{id}/preview', 'name' => 'nowo_yopass_preview'],
            'extend'         => ['path' => '/tools/yopass/{id}/extend', 'name' => 'nowo_yopass_extend'],
            'created'        => ['path' => '/tools/yopass/{id}/created', 'name' => 'nowo_yopass_created'],
            'public_show'    => ['path' => '/share/{id}', 'name' => 'nowo_yopass_public_share'],
            'public_consume' => ['path' => '/share/{id}/consume', 'name' => 'nowo_yopass_public_consume'],
        ];
        $this->templates = [
            'layout'  => '@NowoYopassBundle/layout.html.twig',
            'manage'  => '@NowoYopassBundle/manage/index.html.twig',
            'created' => '@NowoYopassBundle/manage/created.html.twig',
            'public'  => '@NowoYopassBundle/public/reveal.html.twig',
        ];
    }

    public function testIndexRendersManageTemplate(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('countByCreator')->willReturn(1);
        $shareRepository->method('findByCreatorPaginated')->willReturn([$share]);

        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $user);

        $response = $controller->index(Request::create('/tools/yopass'));

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('@NowoYopassBundle/manage/index.html.twig', (string) $response->getContent());
    }

    public function testIndexPurgesOldSharesBeforeListing(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::once())
            ->method('removeByCreatorOlderThan')
            ->with($user, self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(2);
        $shareRepository->expects(self::once())->method('flush');
        $shareRepository->method('countByCreator')->willReturn(0);
        $shareRepository->method('findByCreatorPaginated')->willReturn([]);

        $controller = $this->controller(
            shareRepository: $shareRepository,
            retentionPurger: new ShareRetentionPurger($shareRepository, DefaultShareOptions::get()),
        );
        ControllerContainerBuilder::bind($controller, $user);

        $controller->index(Request::create('/tools/yopass'));
    }

    public function testCreateRedirectsToManageWhenCsrfInvalid(): void
    {
        $controller = $this->controller();
        ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest(null, [
            'ciphertext' => 'encrypted-payload',
            '_token'     => 'invalid',
        ]);

        $response = $controller->create($request);

        self::assertTrue($response->isRedirect('/generated/nowo_yopass_index'));
    }

    public function testCreateRedirectsToManageWhenCiphertextMissing(): void
    {
        $controller = $this->controller();
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container);
        $request->request->remove('share_create');
        $request->request->set('share_create', [
            'expiresIn'   => '1h',
            'maxReads'    => 1,
            'payloadKind' => 'text',
            '_token'      => ControllerContainerBuilder::csrfToken($container, 'yopass_create'),
        ]);

        $response = $controller->create($request);

        self::assertTrue($response->isRedirect('/generated/nowo_yopass_index'));
    }

    public function testCreatePersistsShareAndRedirectsToCreatedPage(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::once())->method('persist');
        $shareRepository->expects(self::once())->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);

        $request = $this->createShareFormRequest($container, [
            'ciphertext' => 'encrypted-payload',
            'expiresIn'  => '1h',
            'maxReads'   => 1,
        ]);

        $response = $controller->create($request);

        self::assertTrue($response->isRedirect('/generated/nowo_yopass_created'));
    }

    public function testCreatedRendersCreatedTemplate(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000020', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $user);

        $response = $controller->created($share->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('@NowoYopassBundle/manage/created.html.twig', (string) $response->getContent());
    }

    public function testCreateRedirectsWhenFilePayloadDisabled(): void
    {
        $controller = $this->controller();
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container, [
            'ciphertext'  => 'encrypted',
            'payloadKind' => 'file',
        ]);

        $response = $controller->create($request);

        self::assertTrue($response->isRedirect('/generated/nowo_yopass_index'));
    }

    public function testCreateAcceptsFilePayloadWhenFileSharesEnabled(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('persist');
        $shareRepository->method('flush');

        $fileHandler = $this->createMock(\Nowo\YopassBundle\Service\ShareFileHandlerInterface::class);
        $fileHandler->method('getMaxFileBytes')->willReturn(512 * 1024);

        $controller = $this->controller(
            shareRepository: $shareRepository,
            fileSharesEnabled: true,
            fileHandler: $fileHandler,
        );
        $container = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container, [
            'ciphertext'  => 'encrypted',
            'payloadKind' => 'file',
        ]);

        self::assertTrue($controller->create($request)->isRedirect('/generated/nowo_yopass_created'));
    }

    public function testCreateAcceptsCustomPayloadKind(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('persist');
        $shareRepository->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container, [
            'ciphertext'  => 'encrypted',
            'payloadKind' => 'unsupported',
        ]);

        self::assertTrue($controller->create($request)->isRedirect('/generated/nowo_yopass_created'));
    }

    public function testPreviewReturnsCiphertextForOwner(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000020', $user);
        $share->setCiphertext('{"v":1,"mode":"key","box":"abc"}')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $shareRetriever = new ShareRetriever($shareRepository);

        $controller = $this->controller(shareRepository: $shareRepository, shareRetriever: $shareRetriever);
        ControllerContainerBuilder::bind($controller, $user);

        $response = $controller->preview($share->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active', $payload['availability']);
        self::assertSame('/share/' . $share->getId(), $payload['publicPath']);
    }

    public function testPreviewThrowsNotFoundForForeignShare(): void
    {
        $owner = new TestUser('owner');
        $other = new TestUser('other');
        $share = new SecureShare('00000000-0000-4000-8000-000000000021', $owner);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $other);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->preview($share->getId());
    }

    public function testExtendUpdatesShareLimits(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000022', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);
        $shareRepository->expects(self::once())->method('persist')->with($share);
        $shareRepository->expects(self::once())->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'yopass_extend');

        $request = Request::create('/tools/yopass/' . $share->getId() . '/extend', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode(['maxReads' => 3], JSON_THROW_ON_ERROR));
        $request->headers->set('X-CSRF-TOKEN', $token);

        $response = $controller->extend($share->getId(), $request);

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertSame(3, $share->getMaxReads());
        self::assertSame(3, $share->getReadsLeft());
    }

    public function testRevokeRedirectsWhenSuccessful(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000010', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);
        $shareRepository->expects(self::once())->method('persist')->with($share);
        $shareRepository->expects(self::once())->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'revoke-share-' . $share->getId());

        $request = Request::create('/tools/yopass/' . $share->getId() . '/revoke', 'POST', [
            '_token' => $token,
        ]);

        $response = $controller->revoke($share->getId(), $request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        self::assertNotNull($share->getRevokedAt());
    }

    public function testRevokeThrowsNotFoundForForeignShare(): void
    {
        $owner = new TestUser('owner');
        $other = new TestUser('other');
        $share = new SecureShare('00000000-0000-4000-8000-000000000011', $owner);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $other);
        $token      = ControllerContainerBuilder::csrfToken($container, 'revoke-share-' . $share->getId());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->revoke($share->getId(), Request::create('/', 'POST', ['_token' => $token]));
    }

    public function testDeleteRemovesShareAndRedirects(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000012', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);
        $shareRepository->expects(self::once())->method('remove')->with($share);
        $shareRepository->expects(self::once())->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'delete-share-' . $share->getId());

        $request = Request::create('/tools/yopass/' . $share->getId() . '/delete', 'POST', [
            '_token' => $token,
        ]);

        $response = $controller->delete($share->getId(), $request);

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testDeleteThrowsNotFoundForForeignShare(): void
    {
        $owner = new TestUser('owner');
        $other = new TestUser('other');
        $share = new SecureShare('00000000-0000-4000-8000-000000000013', $owner);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $other);
        $token      = ControllerContainerBuilder::csrfToken($container, 'delete-share-' . $share->getId());

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->delete($share->getId(), Request::create('/', 'POST', ['_token' => $token]));
    }

    public function testDeleteAllRemovesAllSharesForUser(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::once())->method('removeAllByCreator')->with($user)->willReturn(5);
        $shareRepository->expects(self::once())->method('flush');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'yopass_delete_all');

        $response = $controller->deleteAll(Request::create('/tools/yopass/delete-all', 'POST', [
            '_token' => $token,
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
    }

    public function testDeleteAllThrowsAccessDeniedForInvalidCsrf(): void
    {
        $controller = $this->controller();
        ControllerContainerBuilder::bind($controller, new TestUser());

        $this->expectException(AccessDeniedException::class);
        $controller->deleteAll(Request::create('/', 'POST', ['_token' => 'invalid']));
    }

    public function testDenyUnlessFeatureBlocksAccess(): void
    {
        $accessChecker = $this->createMock(YopassAccessCheckerInterface::class);
        $accessChecker->method('canAccess')->willReturn(false);

        $controller = $this->controller(accessChecker: $accessChecker);
        ControllerContainerBuilder::bind($controller, new TestUser());

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/tools/yopass'));
    }

    public function testDenyUnlessFeatureBlocksListWhenNotGranted(): void
    {
        $accessChecker = $this->createMock(YopassAccessCheckerInterface::class);
        $accessChecker->method('canAccess')->willReturn(true);
        $accessChecker->method('canList')->willReturn(false);

        $controller = $this->controller(accessChecker: $accessChecker);
        ControllerContainerBuilder::bind($controller, new TestUser());

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/tools/yopass'));
    }

    public function testCreateRedirectsWhenCiphertextTooLarge(): void
    {
        $controller = $this->controller(maxCiphertextBytes: 10);
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container, [
            'ciphertext' => '012345678901',
        ]);

        self::assertTrue($controller->create($request)->isRedirect('/generated/nowo_yopass_index'));
    }

    public function testRevokeThrowsAccessDeniedForInvalidCsrf(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000012', $user);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $user);

        $this->expectException(AccessDeniedException::class);
        $controller->revoke($share->getId(), Request::create('/', 'POST', ['_token' => 'invalid']));
    }

    public function testRequireUserDeniesAnonymousAccess(): void
    {
        $controller = $this->controller();
        ControllerContainerBuilder::bind($controller);

        $this->expectException(AccessDeniedException::class);
        $controller->index(Request::create('/tools/yopass'));
    }

    public function testCreateDeniedWhenFeatureNotGranted(): void
    {
        $accessChecker = $this->createMock(YopassAccessCheckerInterface::class);
        $accessChecker->method('canAccess')->willReturn(true);
        $accessChecker->method('canCreate')->willReturn(false);

        $controller = $this->controller(accessChecker: $accessChecker);
        ControllerContainerBuilder::bind($controller, new TestUser());

        $this->expectException(AccessDeniedException::class);
        $controller->create(Request::create('/', 'POST'));
    }

    public function testRevokeDeniedWhenFeatureNotGranted(): void
    {
        $accessChecker = $this->createMock(YopassAccessCheckerInterface::class);
        $accessChecker->method('canAccess')->willReturn(true);
        $accessChecker->method('canRevoke')->willReturn(false);

        $controller = $this->controller(accessChecker: $accessChecker);
        ControllerContainerBuilder::bind($controller, new TestUser());

        $this->expectException(AccessDeniedException::class);
        $controller->revoke('00000000-0000-4000-8000-000000000013', Request::create('/', 'POST'));
    }

    public function testRevokeThrowsNotFoundWhenShareMissing(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn(null);

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());
        $token      = ControllerContainerBuilder::csrfToken($container, 'revoke-share-missing');

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->revoke('missing', Request::create('/', 'POST', ['_token' => $token]));
    }

    public function testDenyUnlessFeatureDefaultBranch(): void
    {
        $controller = $this->controller();
        ControllerContainerBuilder::bind($controller, new TestUser());

        $method = new ReflectionMethod(ShareManageController::class, 'denyUnlessFeature');

        $this->expectException(AccessDeniedException::class);
        $method->invoke($controller, 'unknown');
    }

    private function controller(
        ?YopassAccessCheckerInterface $accessChecker = null,
        ?ShareRepositoryInterface $shareRepository = null,
        ?ShareCreator $shareCreator = null,
        ?ShareRetriever $shareRetriever = null,
        ?ShareExtender $shareExtender = null,
        ?ShareAccessLogger $accessLogger = null,
        ?ShareRetentionPurger $retentionPurger = null,
        int $maxCiphertextBytes = 700_000,
        bool $fileSharesEnabled = false,
        ?\Nowo\YopassBundle\Service\ShareFileHandlerInterface $fileHandler = null,
    ): ShareManageController {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $shareRepository ??= $this->createMock(ShareRepositoryInterface::class);
        $eventDispatcher  = new EventDispatcher();
        $shareLister      = new ShareLister($shareRepository, $eventDispatcher);
        $shareAccessGuard = new ShareAccessGuard($eventDispatcher);
        $shareCreator ??= new ShareCreator($shareRepository, DefaultShareOptions::get()['expiration_options']);
        $shareRetriever ??= new ShareRetriever($shareRepository);
        $shareExtender ??= new ShareExtender($shareRepository, DefaultShareOptions::get());
        $accessLogger ??= new ShareAccessLogger(
            $this->createMock(\Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface::class),
            false,
        );
        $retentionPurger ??= new ShareRetentionPurger($shareRepository, DefaultShareOptions::get());

        return new ShareManageController(
            $accessChecker ?? $this->accessChecker(),
            $shareRepository,
            $shareLister,
            $shareAccessGuard,
            $shareCreator,
            $shareRetriever,
            $shareExtender,
            $accessLogger,
            $retentionPurger,
            $translator,
            $this->routes,
            $this->templates,
            $maxCiphertextBytes,
            512 * 1024,
            'demo_home',
            DefaultShareOptions::get(),
            [
                'default_encryption'    => 'auto',
                'allow_custom_password' => true,
                'default_embed_in_url'  => true,
                'allow_embed_in_url'    => true,
                'show_remember_notice'  => true,
            ],
            $fileSharesEnabled,
            $fileHandler,
        );
    }

    private function accessChecker(): YopassAccessCheckerInterface
    {
        $checker = $this->createMock(YopassAccessCheckerInterface::class);
        $checker->method('canAccess')->willReturn(true);
        $checker->method('canCreate')->willReturn(true);
        $checker->method('canList')->willReturn(true);
        $checker->method('canRevoke')->willReturn(true);

        return $checker;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createShareFormRequest(?Container $container, array $overrides = []): Request
    {
        $token = $container instanceof Container
            ? ControllerContainerBuilder::csrfToken($container, 'yopass_create')
            : 'invalid';

        return Request::create('/tools/yopass/create', 'POST', [
            'share_create' => array_merge([
                'ciphertext'  => 'encrypted-payload',
                'expiresIn'   => '1h',
                'maxReads'    => 1,
                'payloadKind' => 'text',
                '_token'      => $token,
            ], $overrides),
        ]);
    }
}
