<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Controller;

use JsonException;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

use const JSON_THROW_ON_ERROR;

/**
 * Public share retrieval — returns ciphertext only (decryption in browser).
 */
final class PublicShareController extends AbstractController
{
    /**
     * @param array{layout: string, manage: string, public: string} $templates
     * @param array<string, array{path: string, name: string}> $routes
     */
    public function __construct(
        private readonly ShareRepositoryInterface $shareRepository,
        private readonly ShareRetriever $shareRetriever,
        private readonly ShareAccessLogger $accessLogger,
        private readonly array $templates,
        private readonly array $routes,
    ) {
    }

    public function show(string $id): Response
    {
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

        return $this->render($this->templates['public'], [
            'share'           => $share,
            'decryption_mode' => $mode,
            'routes'          => $this->routes,
        ]);
    }

    public function consume(string $id, Request $request): JsonResponse
    {
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
