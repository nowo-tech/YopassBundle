<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\YopassBundle\Controller\ShareManageController;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareAccessCheckEvent;
use Nowo\YopassBundle\Event\ShareCreatedEvent;
use Nowo\YopassBundle\Event\ShareDeleteAllEvent;
use Nowo\YopassBundle\Event\ShareDeletedEvent;
use Nowo\YopassBundle\Event\ShareExtendedEvent;
use Nowo\YopassBundle\Event\ShareListColumnsEvent;
use Nowo\YopassBundle\Event\ShareListFiltersEvent;
use Nowo\YopassBundle\Event\ShareListQueryEvent;
use Nowo\YopassBundle\Event\ShareListRouteEvent;
use Nowo\YopassBundle\Event\ShareRevokedEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareDeleter;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareFileHandlerInterface;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Service\ShareListRouteResolver;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Service\ShareRevoker;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\ControllerContainerBuilder;
use Nowo\YopassBundle\Tests\Support\DefaultShareOptions;
use Nowo\YopassBundle\Tests\Support\SecretCapturingLogger;
use Nowo\YopassBundle\Ui\ListRoute;
use Nowo\YopassBundle\Ui\ShareListColumn;
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Nowo\YopassBundle\Ui\YopassPages;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

use const JSON_THROW_ON_ERROR;

final class ShareManageControllerTest extends TestCase
{
    /** @var array<string, array{path: string, name: string}> */
    private array $routes;

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

    public function testIndexPassesTheListPageWithPagination(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('countByCreator')->willReturn(45);
        $shareRepository->method('findByCreatorPaginated')->willReturn([$share]);

        $twig = new class {
            /** @var array<string, mixed> */
            public array $captured = [];

            /** @param array<string, mixed> $context */
            public function render(string $name, array $context = []): string
            {
                $this->captured = $context;

                return $name;
            }
        };

        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $user, ['twig' => $twig]);

        $controller->index(Request::create('/tools/yopass', 'GET', ['page' => 2]));

        $page = $twig->captured['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertNotNull($page->pagination);
        self::assertSame(2, $page->pagination->page);
        self::assertSame(3, $page->pagination->totalPages);
        self::assertSame('nowo_yopass_index', $page->pagination->route);
        self::assertSame('/generated/demo_home', $page->header->back?->href);
        self::assertCount(1, $page->header->actions);
        self::assertNull($twig->captured['nowo_yopass_legacy_layout']);
    }

    public function testIndexNeverPurgesOnGet(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('removeByCreatorOlderThan');
        $shareRepository->expects(self::never())->method('removeOlderThan');
        $shareRepository->expects(self::never())->method('flush');
        $shareRepository->method('countByCreator')->willReturn(0);
        $shareRepository->method('findByCreatorPaginated')->willReturn([]);

        $controller = $this->controller(
            shareRepository: $shareRepository,
            retentionPurger: new ShareRetentionPurger($shareRepository, DefaultShareOptions::get()),
        );
        ControllerContainerBuilder::bind($controller, $user);

        $controller->index(Request::create('/tools/yopass'));
    }

    public function testCreatePurgesTheCreatorsOldSharesOnTheWritePath(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::once())->method('persist');
        $shareRepository->expects(self::once())
            ->method('removeByCreatorOlderThan')
            ->with($user, self::isInstanceOf(DateTimeImmutable::class))
            ->willReturn(2);

        $controller = $this->controller(
            shareRepository: $shareRepository,
            retentionPurger: new ShareRetentionPurger($shareRepository, DefaultShareOptions::get()),
        );
        $container = ControllerContainerBuilder::bind($controller, $user);

        $response = $controller->create($this->createShareFormRequest($container));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
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
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
        self::assertTrue($response->headers->hasCacheControlDirective('private'));
    }

    public function testCreatedPassesTheEmbedOptionToTheLinksForm(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000021', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $twig       = new class {
            /** @var array<string, mixed> */
            public array $context = [];

            /**
             * @param array<string, mixed> $context
             */
            public function render(string $name, array $context = []): string
            {
                $this->context = $context;

                return $name;
            }
        };
        $container->set('twig', $twig);

        $controller->created($share->getId());

        $links = $twig->context['links_form'];
        self::assertInstanceOf(FormView::class, $links);
        // The test controller is configured with sharing.allow_embed_in_url: true.
        self::assertArrayHasKey('oneClickLink', $links->children);
        self::assertTrue($twig->context['sharing_options']['allow_embed_in_url']);
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

        $fileHandler = $this->createMock(ShareFileHandlerInterface::class);
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

    public function testCreateRejectsUnknownPayloadKind(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('persist');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $request = $this->createShareFormRequest($container, [
            'ciphertext'  => 'encrypted',
            'payloadKind' => 'unsupported',
        ]);

        self::assertTrue($controller->create($request)->isRedirect('/generated/nowo_yopass_index'));
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
        ControllerContainerBuilder::bind($controller, $user, ['router' => $this->prefixedRouter()]);

        $response = $controller->preview($share->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active', $payload['availability']);
        // route_prefix ("/app") and the request base path ("/sub") come from the router, not the raw config path.
        self::assertSame('/sub/app/share/' . $share->getId(), $payload['publicPath']);
    }

    public function testCreatedPublicPathUsesRouterWithPrefixAndPlaceholders(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000020', $user);
        $share->setCiphertext('x')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $routes = new RouteCollection();
        $routes->add('nowo_yopass_public_share', new Route('/{_locale}/share/{id}'));
        $routes->add('nowo_yopass_index', new Route('/{_locale}/tools/yopass'));
        $context = new RequestContext('/sub');
        $context->setParameter('_locale', 'es');

        $twig = new class {
            /** @var array<string, mixed> */
            public array $captured = [];

            /** @param array<string, mixed> $context */
            public function render(string $name, array $context = []): string
            {
                $this->captured = $context;

                return $name;
            }
        };

        $controller = $this->controller(shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, $user, [
            'router' => new UrlGenerator($routes, $context),
            'twig'   => $twig,
        ]);

        $controller->created($share->getId());

        self::assertSame('/sub/es/share/' . $share->getId(), $twig->captured['public_path']);
    }

    private function prefixedRouter(): UrlGenerator
    {
        $routes = new RouteCollection();
        $routes->add('nowo_yopass_public_share', new Route('/app/share/{id}'));

        return new UrlGenerator($routes, new RequestContext('/sub'));
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

        $this->expectException(NotFoundHttpException::class);
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

        $this->expectException(NotFoundHttpException::class);
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

        $this->expectException(NotFoundHttpException::class);
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

    public function testDeleteAllVetoedByListenerRemovesNothing(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('removeAllByCreator');
        $shareRepository->expects(self::never())->method('flush');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            $event->veto();
        });

        $controller = $this->controller(shareRepository: $shareRepository, shareDeleter: new ShareDeleter($shareRepository, $dispatcher));
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'yopass_delete_all');

        $response = $controller->deleteAll(Request::create('/tools/yopass/delete-all', 'POST', [
            '_token' => $token,
        ]));

        self::assertSame(Response::HTTP_FOUND, $response->getStatusCode());
        $session = $container->get('request_stack')->getSession();
        self::assertSame(['yopass.error.delete_all_denied'], $session->getFlashBag()->get('error'));
    }

    public function testDeleteDispatchesShareDeletedEvent(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-000000000099', $user);
        $share->setCiphertext('PLACEHOLDER-CIPHERTEXT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETED, static function (ShareDeletedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $controller = $this->controller(shareRepository: $shareRepository, shareDeleter: new ShareDeleter($shareRepository, $dispatcher));
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'delete-share-' . $share->getId());

        $controller->delete($share->getId(), Request::create('/', 'POST', ['_token' => $token]));

        self::assertCount(1, $events);
        self::assertSame($share->getId(), $events[0]->getShareId());
        self::assertSame($user, $events[0]->getDeletedBy());
    }

    /**
     * EL-I2: no log record (PSR logger, PHP error_log) and no flash message produced while creating, deleting and
     * deleting all shares contains the ciphertext or key material. Placeholders only.
     */
    public function testCreateDeleteAndDeleteAllNeverLogOrFlashSecretMaterial(): void
    {
        $marker     = 'PLACEHOLDER-SECRET-MATERIAL-7f3a';
        $ciphertext = '{"v":1,"mode":"key","nonce":"' . $marker . '-nonce","ciphertext":"' . $marker . '-ct"}';
        $user       = new TestUser();
        $stored     = null;

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('persist')->willReturnCallback(static function (SecureShare $share) use (&$stored): void {
            $stored = $share;
        });
        $shareRepository->method('find')->willReturnCallback(static function () use (&$stored): ?SecureShare {
            return $stored;
        });
        $shareRepository->method('removeAllByCreator')->willReturn(3);

        $logger     = new SecretCapturingLogger();
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_DELETE_ALL, static function (ShareDeleteAllEvent $event): void {
            $event->markHandled(3);
            $event->veto(); // late veto: ignored and logged, must not leak anything either
        });

        $errorLog    = (string) tempnam(sys_get_temp_dir(), 'yopass-errlog');
        $previousLog = ini_set('error_log', $errorLog);

        try {
            $controller = $this->controller(
                shareRepository: $shareRepository,
                shareCreator: new ShareCreator($shareRepository, DefaultShareOptions::get()['expiration_options'], null, $dispatcher),
                shareDeleter: new ShareDeleter($shareRepository, $dispatcher, null, $logger),
            );
            $container = ControllerContainerBuilder::bind($controller, $user);

            $controller->create($this->createShareFormRequest($container, ['ciphertext' => $ciphertext]));
            $controller->create($this->createShareFormRequest($container, ['ciphertext' => $ciphertext, 'maxReads' => 999]));
            self::assertInstanceOf(SecureShare::class, $stored);

            $shareId = $stored->getId();
            $controller->delete($shareId, Request::create('/', 'POST', ['_token' => ControllerContainerBuilder::csrfToken($container, 'delete-share-' . $shareId)]));
            $controller->deleteAll(Request::create('/', 'POST', ['_token' => ControllerContainerBuilder::csrfToken($container, 'yopass_delete_all')]));

            $flashes = $container->get('request_stack')->getSession()->getFlashBag()->all();
        } finally {
            ini_set('error_log', $previousLog === false ? '' : $previousLog);
        }

        $logged = (string) file_get_contents($errorLog);
        @unlink($errorLog);

        self::assertNotSame([], $logger->records, 'the late veto must be logged');
        self::assertStringNotContainsString($marker, print_r($logger->records, true), 'PSR log records leak secret material');
        self::assertStringNotContainsString($marker, $logged, 'PHP error_log leaks secret material');
        self::assertNotSame([], $flashes);
        self::assertStringNotContainsString($marker, print_r($flashes, true), 'flash messages leak secret material');
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

        $this->expectException(NotFoundHttpException::class);
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

    public function testJsonCreateAnswers201WithIdsOnlyAndDispatchesTheEvent(): void
    {
        $user       = new TestUser();
        $ciphertext = '{"v":1,"ciphertext":"PLACEHOLDER-JSON-CT"}';

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::once())->method('persist');

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_CREATED, static function (ShareCreatedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $controller = $this->controller(
            shareRepository: $shareRepository,
            shareCreator: new ShareCreator($shareRepository, DefaultShareOptions::get()['expiration_options'], null, $dispatcher),
            createMode: 'dialog',
        );
        $container = ControllerContainerBuilder::bind($controller, $user);
        $request   = $this->jsonCreateRequest($container, ['ciphertext' => $ciphertext]);

        $response = $controller->create($request);

        self::assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        /** @var array<string, string> $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['id', 'public_path', 'created_url'], array_keys($body));
        self::assertSame('/generated/nowo_yopass_public_share', $body['public_path']);
        self::assertStringNotContainsString('PLACEHOLDER', (string) $response->getContent());
        self::assertCount(1, $events);
        self::assertSame($body['id'], $events[0]->getShareId());
        self::assertSame([], $container->get('request_stack')->getSession()->getFlashBag()->peekAll());
    }

    public function testJsonCreateAnswers422WithoutFlashOnInvalidCsrf(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('persist');

        $controller = $this->controller(shareRepository: $shareRepository, createMode: 'dialog');
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());
        $request    = $this->jsonCreateRequest($container, ['_token' => 'invalid']);

        $response = $controller->create($request);

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('{"error":"CREATE_FAILED"}', $response->getContent());
        self::assertSame([], $container->get('request_stack')->getSession()->getFlashBag()->peekAll());
    }

    public function testJsonCreateAnswers422WhenNothingIsSubmitted(): void
    {
        $controller = $this->controller(createMode: 'dialog');
        ControllerContainerBuilder::bind($controller, new TestUser());
        $request = Request::create('/tools/yopass/create', 'POST');
        $request->headers->set('Accept', 'application/json');

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $controller->create($request)->getStatusCode());
    }

    public function testJsonCreateAnswers422WhenFileSharesAreDisabled(): void
    {
        $controller = $this->controller(createMode: 'dialog');
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());

        $response = $controller->create($this->jsonCreateRequest($container, ['payloadKind' => 'file']));

        self::assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $response->getStatusCode());
        self::assertSame('{"error":"FILE_NOT_ENABLED"}', $response->getContent());
    }

    public function testJsonCreateRefusesACrossSiteFetch(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('persist');

        $controller = $this->controller(shareRepository: $shareRepository, createMode: 'dialog');
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());
        $request    = $this->jsonCreateRequest($container);
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        $response = $controller->create($request);

        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        self::assertSame('{"error":"CROSS_SITE"}', $response->getContent());
    }

    public function testPageModeCreateRefusesACrossSiteFormPost(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->expects(self::never())->method('persist');

        $controller = $this->controller(shareRepository: $shareRepository);
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());
        $request    = $this->createShareFormRequest($container);
        $request->headers->set('Sec-Fetch-Site', 'cross-site');

        $this->expectException(AccessDeniedException::class);
        $controller->create($request);
    }

    public function testPageModeCreateAcceptsASameOriginFormPost(): void
    {
        $controller = $this->controller();
        $container  = ControllerContainerBuilder::bind($controller, new TestUser());
        $request    = $this->createShareFormRequest($container);
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        self::assertSame(Response::HTTP_FOUND, $controller->create($request)->getStatusCode());
    }

    public function testIndexInDialogModeAddsTheNewShareActionsAndTheResultForm(): void
    {
        $user = new TestUser();

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('countByCreator')->willReturn(0);
        $shareRepository->method('findByCreatorPaginated')->willReturn([]);

        $twig       = $this->capturingTwig();
        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository, createMode: 'dialog');
        ControllerContainerBuilder::bind($controller, $user, ['twig' => $twig]);

        $controller->index(Request::create('/tools/yopass'));

        $page = $twig->captured['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertSame('dialog', $twig->captured['create_mode']);
        self::assertInstanceOf(FormView::class, $twig->captured['links_form']);
        // One create action per screen: an empty list carries it in the empty state only.
        self::assertSame([], $page->header->actions, 'no header New share (nor Delete all) on an empty list');
        self::assertSame(YopassPageFactory::CREATE_DIALOG_ID, $page->empty?->action?->dialogId);
        self::assertSame('yopass-create-open-empty', $page->empty?->action?->attributes['data-testid']);
    }

    public function testIndexGatesRowActionsPerShareAndAddsHostColumns(): void
    {
        $user    = new TestUser('viewer');
        $own     = new SecureShare('00000000-0000-4000-8000-0000000000c1', $user);
        $foreign = new SecureShare('00000000-0000-4000-8000-0000000000c2', new TestUser('other'));
        foreach ([$own, $foreign] as $share) {
            $share->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);
        }

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('countByCreator')->willReturn(1);

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event) use ($own, $foreign): void {
            $event->overrideList([$own, $foreign], 2);
        });
        // The foreign share may only be previewed.
        $dispatcher->addListener(YopassEvents::SHARE_ACCESS_CHECK, static function (ShareAccessCheckEvent $event) use ($foreign): void {
            if ($event->getShare() === $foreign && $event->getAction() === ShareAccessAction::Preview) {
                $event->grant();
            }
        });
        $dispatcher->addListener(YopassEvents::SHARE_LIST_COLUMNS, static function (ShareListColumnsEvent $event): void {
            $event->addColumn(new ShareListColumn('createdBy', 'Created by', static fn (SecureShare $share): string => '<b>' . $share->getId() . '</b>', 2));
        });

        $twig       = $this->capturingTwig();
        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository, dispatcher: $dispatcher);
        ControllerContainerBuilder::bind($controller, $user, ['twig' => $twig]);

        $controller->index(Request::create('/tools/yopass'));

        $page = $twig->captured['page'];
        self::assertInstanceOf(ListPage::class, $page);
        $kinds = static fn (SecureShare $share): array => array_map(static fn (Action $action): string => $action->kind, $page->rowActions($share));
        self::assertSame(['view', 'extend', 'revoke', 'delete'], $kinds($own));
        self::assertSame(['view'], $kinds($foreign));
        self::assertArrayNotHasKey($foreign->getId(), $twig->captured['revoke_forms']);
        self::assertArrayNotHasKey($foreign->getId(), $twig->captured['delete_forms']);
        self::assertSame(1, $twig->captured['own_total']);
        self::assertSame('createdBy', $page->columns[array_key_last($page->columns)]->key);
        self::assertSame('<b>' . $foreign->getId() . '</b>', $twig->captured['extra_cells'][$foreign->getId()]['createdBy']);
        self::assertSame('page', $twig->captured['create_mode']);
        self::assertNull($twig->captured['links_form']);
    }

    public function testRevokeDispatchesShareRevokedEvent(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-0000000000c3', $user);
        $share->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_REVOKED, static function (ShareRevokedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $controller = $this->controller(shareRepository: $shareRepository, dispatcher: $dispatcher);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $token      = ControllerContainerBuilder::csrfToken($container, 'revoke-share-' . $share->getId());

        $controller->revoke($share->getId(), Request::create('/', 'POST', ['_token' => $token]));

        self::assertCount(1, $events);
        self::assertSame($share->getId(), $events[0]->getShareId());
        self::assertSame($user, $events[0]->getRevokedBy());
        self::assertNotNull($share->getRevokedAt());
    }

    public function testExtendDispatchesShareExtendedEvent(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-0000000000c4', $user);
        $share->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        $events     = [];
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_EXTENDED, static function (ShareExtendedEvent $event) use (&$events): void {
            $events[] = $event;
        });

        $controller = $this->controller(shareRepository: $shareRepository, dispatcher: $dispatcher);
        $container  = ControllerContainerBuilder::bind($controller, $user);
        $request    = Request::create('/', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode(['maxReads' => 3], JSON_THROW_ON_ERROR));
        $request->headers->set('X-CSRF-TOKEN', ControllerContainerBuilder::csrfToken($container, 'yopass_extend'));

        $controller->extend($share->getId(), $request);

        self::assertCount(1, $events);
        self::assertSame(1, $events[0]->getOldMaxReads());
        self::assertSame(3, $events[0]->getNewMaxReads());
        self::assertSame($user, $events[0]->getExtendedBy());
        self::assertSame($events[0]->getOldExpiresAt(), $events[0]->getNewExpiresAt());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function jsonCreateRequest(Container $container, array $overrides = []): Request
    {
        $request = $this->createShareFormRequest($container, $overrides);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');

        return $request;
    }

    private function capturingTwig(): object
    {
        return new class {
            /** @var array<string, mixed> */
            public array $captured = [];

            /** @param array<string, mixed> $context */
            public function render(string $name, array $context = []): string
            {
                $this->captured = $context;

                return $name;
            }
        };
    }

    public function testIndexOnAHostPageUsesTheHostRouteForPagerFiltersAndActionForms(): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-0000000000f1', $user);
        $share->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('countByCreator')->willReturn(45);
        $shareRepository->method('findByCreatorPaginated')->willReturn([$share]);

        $seenFilters = null;
        $dispatcher  = new EventDispatcher();
        $dispatcher->addListener(YopassEvents::SHARE_LIST_ROUTE, static function (ShareListRouteEvent $event): void {
            $event->setRoute('app_project_secrets', ['id' => '7']);
        });
        $dispatcher->addListener(YopassEvents::SHARE_LIST_FILTERS, static function (ShareListFiltersEvent $event): void {
            self::assertInstanceOf(TestUser::class, $event->getViewer());
            $event->getFormBuilder()->add('sharedWith', TextType::class, ['required' => false]);
        });
        $dispatcher->addListener(YopassEvents::SHARE_LIST_QUERY, static function (ShareListQueryEvent $event) use (&$seenFilters): void {
            $seenFilters = $event->getFilters();
        });

        $twig       = $this->capturingTwig();
        $resolver   = new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets'], 'placeholder-secret', $dispatcher);
        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository, dispatcher: $dispatcher, listRoutes: $resolver);
        ControllerContainerBuilder::bind($controller, $user, ['twig' => $twig, 'router' => $this->paramRouter()]);

        $controller->index(Request::create('/projects/7/secrets', 'GET', ['page' => 2, 'filters' => ['sharedWith' => '<b>team</b>', 'junk' => ['x' => 'y']]]));

        $page = $twig->captured['page'];
        self::assertInstanceOf(ListPage::class, $page);
        self::assertNotNull($page->pagination);
        self::assertSame('app_project_secrets', $page->pagination->route);
        self::assertSame(['id' => '7'], $page->pagination->routeParams);
        self::assertSame(['filters' => ['sharedWith' => '<b>team</b>', 'junk' => ['x' => 'y']]], $page->pagination->query);
        self::assertInstanceOf(FormView::class, $page->filters);
        self::assertSame('GET', $page->filters->vars['method']);
        self::assertSame('/generated/app_project_secrets?id=7', $page->filters->vars['action']);
        self::assertSame('/generated/app_project_secrets?id=7', $page->filtersClearHref);
        self::assertSame(['sharedWith' => '<b>team</b>'], $seenFilters);
        self::assertSame('app_project_secrets', $twig->captured['list_route']->name);

        $token = $twig->captured['revoke_forms'][$share->getId()]->children[ShareListRouteResolver::FIELD]->vars['value'];
        self::assertSame($resolver->sign($user, $twig->captured['list_route']), $token);
        self::assertSame($token, $twig->captured['delete_forms'][$share->getId()]->children[ShareListRouteResolver::FIELD]->vars['value']);
        self::assertSame($token, $twig->captured['delete_all_form']->children[ShareListRouteResolver::FIELD]->vars['value']);
    }

    public function testIndexWithoutFilterFieldsHasNoFilterForm(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('findByCreatorPaginated')->willReturn([]);

        $twig       = $this->capturingTwig();
        $controller = $this->controller(accessChecker: $this->accessChecker(), shareRepository: $shareRepository);
        ControllerContainerBuilder::bind($controller, new TestUser(), ['twig' => $twig]);

        $controller->index(Request::create('/tools/yopass'));

        self::assertNull($twig->captured['page']->filters);
        self::assertSame([], $twig->captured['list_filters']);
        self::assertArrayNotHasKey(ShareListRouteResolver::FIELD, $twig->captured['delete_all_form']->children);
    }

    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function listTokens(): iterable
    {
        yield 'valid host token' => ['valid', '/generated/app_project_secrets?id=7'];
        yield 'no token' => [null, '/generated/nowo_yopass_index'];
        yield 'forged token' => ['forged', '/generated/nowo_yopass_index'];
        yield 'free URL' => ['https://evil.example/x', '/generated/nowo_yopass_index'];
        yield 'route the router cannot build' => ['broken', '/generated/nowo_yopass_index'];
    }

    #[DataProvider('listTokens')]
    public function testRevokeDeleteAndDeleteAllRedirectToTheVerifiedListPage(?string $kind, string $expected): void
    {
        $user  = new TestUser();
        $share = new SecureShare('00000000-0000-4000-8000-0000000000f2', $user);
        $share->setCiphertext('PLACEHOLDER-CT')->setExpiresAt(new DateTimeImmutable('+1 hour'))->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);
        $shareRepository->method('removeAllByCreator')->willReturn(1);

        $resolver = new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets', 'app_broken'], 'placeholder-secret');
        $token    = match ($kind) {
            'valid'  => $resolver->sign($user, new ListRoute('app_project_secrets', ['id' => '7'])),
            'forged' => (new ShareListRouteResolver('nowo_yopass_index', ['app_project_secrets'], 'other-secret'))->sign($user, new ListRoute('app_project_secrets', ['id' => '7'])),
            'broken' => $resolver->sign($user, new ListRoute('app_broken')),
            default  => $kind,
        };

        $controller = $this->controller(shareRepository: $shareRepository, listRoutes: $resolver);
        $container  = ControllerContainerBuilder::bind($controller, $user, ['router' => $this->paramRouter()]);
        $post       = static fn (string $csrfId): Request => Request::create('/', 'POST', array_filter([
            '_token'                      => ControllerContainerBuilder::csrfToken($container, $csrfId),
            ShareListRouteResolver::FIELD => $token,
        ], static fn (?string $value): bool => $value !== null));

        self::assertSame($expected, $controller->revoke($share->getId(), $post('revoke-share-' . $share->getId()))->getTargetUrl());
        self::assertSame($expected, $controller->delete($share->getId(), $post('delete-share-' . $share->getId()))->getTargetUrl());
        self::assertSame($expected, $controller->deleteAll($post('yopass_delete_all'))->getTargetUrl());
    }

    private function paramRouter(): UrlGenerator
    {
        return new class(new RouteCollection(), new RequestContext()) extends UrlGenerator {
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                if ($name === 'app_broken') {
                    throw new RouteNotFoundException('placeholder');
                }

                return '/generated/' . $name . ($parameters !== [] ? '?' . http_build_query($parameters) : '');
            }
        };
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
        ?ShareFileHandlerInterface $fileHandler = null,
        ?ShareDeleter $shareDeleter = null,
        ?EventDispatcher $dispatcher = null,
        string $createMode = 'page',
        ?ShareRevoker $shareRevoker = null,
        ?ShareListRouteResolver $listRoutes = null,
    ): ShareManageController {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $shareRepository ??= $this->createMock(ShareRepositoryInterface::class);
        $eventDispatcher  = $dispatcher ?? new EventDispatcher();
        $shareLister      = new ShareLister($shareRepository, $eventDispatcher);
        $shareAccessGuard = new ShareAccessGuard($eventDispatcher);
        $shareCreator ??= new ShareCreator($shareRepository, DefaultShareOptions::get()['expiration_options']);
        $shareRetriever ??= new ShareRetriever($shareRepository);
        $shareExtender ??= new ShareExtender($shareRepository, DefaultShareOptions::get(), null, $eventDispatcher);
        $accessLogger ??= new ShareAccessLogger(
            $this->createMock(ShareAccessLogRepositoryInterface::class),
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
            new YopassPageFactory(new YopassPages()),
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
            $shareDeleter,
            $shareRevoker ?? new ShareRevoker($shareRepository, $eventDispatcher),
            $eventDispatcher,
            $createMode,
            $listRoutes,
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
