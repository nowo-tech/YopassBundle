<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Controller;

use DateTimeImmutable;
use Nowo\YopassBundle\Controller\PublicShareController;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Tests\Support\ControllerContainerBuilder;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PublicShareControllerTest extends TestCase
{
    /** @var array{layout: string, manage: string, public: string} */
    private array $templates;

    /** @var array<string, array{path: string, name: string}> */
    private array $routes;

    protected function setUp(): void
    {
        $this->templates = [
            'layout' => '@NowoYopassBundle/layout.html.twig',
            'manage' => '@NowoYopassBundle/manage/index.html.twig',
            'public' => '@NowoYopassBundle/public/reveal.html.twig',
        ];
        $this->routes = [
            'manage'         => ['path' => '/tools/yopass', 'name' => 'nowo_yopass_index'],
            'create'         => ['path' => '/tools/yopass/create', 'name' => 'nowo_yopass_create'],
            'revoke'         => ['path' => '/tools/yopass/{id}/revoke', 'name' => 'nowo_yopass_revoke'],
            'public_show'    => ['path' => '/share/{id}', 'name' => 'nowo_yopass_public_share'],
            'public_consume' => ['path' => '/share/{id}/consume', 'name' => 'nowo_yopass_public_consume'],
        ];
    }

    public function testShowRendersPublicTemplateWithModeFromJson(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000001', new TestUser());
        $share
            ->setCiphertext('{"mode":"password","box":"abc"}')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $controller = $this->controllerWithShare($share);
        ControllerContainerBuilder::bind($controller);

        $response = $controller->show($share->getId());

        self::assertSame(Response::HTTP_OK, $response->getStatusCode());
        self::assertStringContainsString('@NowoYopassBundle/public/reveal.html.twig', (string) $response->getContent());
    }

    public function testShowUsesKeyModeForLegacyCiphertext(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000002', new TestUser());
        $share
            ->setCiphertext('legacy-not-json')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $controller = $this->controllerWithShare($share);
        ControllerContainerBuilder::bind($controller);

        self::assertSame(Response::HTTP_OK, $controller->show($share->getId())->getStatusCode());
    }

    public function testShowThrowsNotFoundWhenMissing(): void
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn(null);

        $controller = new PublicShareController(
            $shareRepository,
            new ShareRetriever($shareRepository),
            $this->accessLogger(),
            $this->templates,
            $this->routes,
        );
        ControllerContainerBuilder::bind($controller);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        $controller->show('missing');
    }

    public function testConsumeReturnsOkOrGone(): void
    {
        $share = new SecureShare('00000000-0000-4000-8000-000000000003', new TestUser());
        $share
            ->setCiphertext('{"v":1,"mode":"key","box":"abc"}')
            ->setExpiresAt(new DateTimeImmutable('+1 hour'))
            ->setMaxReads(1);

        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturnOnConsecutiveCalls($share, $share, null);
        $shareRepository->method('persist');
        $shareRepository->method('flush');

        $controller = new PublicShareController(
            $shareRepository,
            new ShareRetriever($shareRepository),
            $this->accessLogger(),
            $this->templates,
            $this->routes,
        );

        $request = Request::create('/share/' . $share->getId() . '/consume', 'POST');

        $ok   = $controller->consume($share->getId(), $request);
        $gone = $controller->consume('missing', Request::create('/share/missing/consume', 'POST'));

        self::assertSame(Response::HTTP_OK, $ok->getStatusCode());
        self::assertSame(Response::HTTP_GONE, $gone->getStatusCode());
    }

    private function controllerWithShare(SecureShare $share): PublicShareController
    {
        $shareRepository = $this->createMock(ShareRepositoryInterface::class);
        $shareRepository->method('find')->willReturn($share);

        return new PublicShareController(
            $shareRepository,
            new ShareRetriever($shareRepository),
            $this->accessLogger(),
            $this->templates,
            $this->routes,
        );
    }

    private function accessLogger(): ShareAccessLogger
    {
        $repository = $this->createMock(\Nowo\YopassBundle\Repository\ShareAccessLogRepositoryInterface::class);

        return new ShareAccessLogger($repository, false);
    }
}
