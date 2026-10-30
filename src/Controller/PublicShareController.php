<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Controller;

use JsonException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Form\ShareRevealType;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Routing\YopassRouteLoader;
use Nowo\YopassBundle\Security\PublicEndpointRateLimiter;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

use const JSON_THROW_ON_ERROR;

/**
 * Public share retrieval — returns ciphertext only (decryption in browser).
 *
 * Paths and methods are declared with {@see Route} attributes (REQ-SF-004);
 * {@see YopassRouteLoader} imports them and applies config path/name overrides.
 */
#[AsController]
final class PublicShareController extends AbstractController
{
    /**
     * Creates the public share controller.
     *
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
        private readonly ShareRetriever $shareRetriever,
        private readonly ShareAccessLogger $accessLogger,
        private readonly PublicEndpointRateLimiter $rateLimiter,
        private readonly YopassPageFactory $pages,
        private readonly array $routes,
    ) {
    }

    /**
     * Renders the public reveal page for a share (ciphertext is fetched and decrypted in the browser).
     */
    #[Route('/share/{id}', name: 'nowo_yopass_public_share', methods: ['GET'])]
    public function show(string $id, Request $request): Response
    {
        $this->rateLimiter->consume($request, 'show');
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare) {
            throw $this->createNotFoundException();
        }

        $mode = 'key';

        try {
            /** @var array{mode?: string} $parsed */
            $parsed = json_decode($share->getCiphertext(), true, 512, JSON_THROW_ON_ERROR);
            $mode   = (string) ($parsed['mode'] ?? 'key');
        } catch (JsonException) {
        }

        // REQ-UI-003: unique page on the guest layout (page_layout null = nowo_ui_kit.layouts.guest).
        $response = $this->render($this->pages->template('public'), [
            'header'          => $this->pages->publicHeader(),
            'page_layout'     => $this->pages->publicLayout(),
            'share'           => $share,
            'decryption_mode' => $mode,
            'routes'          => $this->routes,
            'reveal_form'     => $this->createForm(ShareRevealType::class, null, ['decryption_mode' => $mode])->createView(),
        ]);

        return self::hardenSecretResponse($response);
    }

    /**
     * Marks a page that handles secret material (fragment key, decrypted plaintext, share links) as
     * non-cacheable and stops the browser from leaking its URL through the Referer header (REQ-SEC-004).
     */
    public static function hardenSecretResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /**
     * Consumes one read of a share and returns its ciphertext as JSON.
     */
    #[Route('/share/{id}/consume', name: 'nowo_yopass_public_consume', methods: ['POST'])]
    public function consume(string $id, Request $request): JsonResponse
    {
        $this->rateLimiter->consume($request, 'consume');

        $result = $this->shareRetriever->consume($id);

        if ($result['status'] === 'ok') {
            $share = $this->shareRepository->find($id);

            if ($share instanceof SecureShare) {
                $this->accessLogger->logSuccessfulConsume($share, $request);
            }
        }

        $statusCode = match ($result['status']) {
            'ok'    => Response::HTTP_OK,
            default => Response::HTTP_GONE,
        };

        return new JsonResponse($result, $statusCode);
    }
}
