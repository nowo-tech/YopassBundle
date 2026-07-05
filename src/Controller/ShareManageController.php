<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Controller;

use DateTimeInterface;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Form\ShareCreateType;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Security\YopassAccessCheckerInterface;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Nowo\YopassBundle\Service\ShareAccessLogger;
use Nowo\YopassBundle\Service\ShareCreator;
use Nowo\YopassBundle\Service\ShareExtender;
use Nowo\YopassBundle\Service\ShareFileHandlerInterface;
use Nowo\YopassBundle\Service\ShareLister;
use Nowo\YopassBundle\Service\ShareRetentionPurger;
use Nowo\YopassBundle\Service\ShareRetriever;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Authenticated Yopass-style share management (E2E encrypted in the browser).
 */
final class ShareManageController extends AbstractController
{
    /**
     * @param array<string, array{path: string, name: string}> $routes
     * @param array{layout: string, manage: string, created: string, public: string} $templates
     * @param array{
     *     default_expiration: string,
     *     default_max_reads: int,
     *     max_reads_options: list<int>,
     *     expiration_options: list<array{id: string, interval: string}>,
     *     list_page_size?: int
     * } $shareOptions
     * @param array{
     *     default_encryption: string,
     *     allow_custom_password: bool,
     *     default_embed_in_url: bool,
     *     allow_embed_in_url: bool,
     *     show_remember_notice: bool
     * } $sharingOptions
     */
    public function __construct(
        private readonly YopassAccessCheckerInterface $accessChecker,
        private readonly ShareRepositoryInterface $shareRepository,
        private readonly ShareLister $shareLister,
        private readonly ShareAccessGuard $shareAccessGuard,
        private readonly ShareCreator $shareCreator,
        private readonly ShareRetriever $shareRetriever,
        private readonly ShareExtender $shareExtender,
        private readonly ShareAccessLogger $accessLogger,
        private readonly ShareRetentionPurger $retentionPurger,
        private readonly TranslatorInterface $translator,
        private readonly array $routes,
        private readonly array $templates,
        private readonly int $maxCiphertextBytes,
        private readonly int $maxSecretChars,
        private readonly ?string $dashboardRoute,
        private readonly array $shareOptions,
        private readonly array $sharingOptions,
        private readonly bool $fileSharesEnabled,
        private readonly ?ShareFileHandlerInterface $fileHandler = null,
    ) {
    }

    public function index(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        $user = $this->requireUser();

        $this->retentionPurger->purgeForCreator($user);

        $pageSize   = (int) ($this->shareOptions['list_page_size'] ?? 0);
        $page       = max(1, $request->query->getInt('page', 1));
        $list       = $this->shareLister->list($user, $pageSize, $page);
        $shares     = $list['shares'];
        $total      = $list['total'];
        $totalPages = $pageSize > 0 ? (int) max(1, ceil($total / $pageSize)) : 1;
        $page       = min($page, $totalPages);

        $createForm = null;
        if ($this->accessChecker->canCreate($user)) {
            $createForm = $this->buildShareCreateForm()->createView();
        }

        return $this->render($this->templates['manage'], [
            'shares'           => $shares,
            'shares_total'     => $total,
            'shares_page'      => $page,
            'shares_pages'     => $totalPages,
            'list_page_size'   => $pageSize,
            'can_create'       => $this->accessChecker->canCreate($user),
            'can_revoke'       => $this->accessChecker->canRevoke($user),
            'can_extend'       => $this->accessChecker->canRevoke($user),
            'can_share_file'   => $this->fileSharesEnabled,
            'max_file_bytes'   => $this->fileHandler?->getMaxFileBytes() ?? 0,
            'max_secret_chars' => $this->maxSecretChars,
            'dashboard_route'  => $this->dashboardRoute,
            'routes'           => $this->routes,
            'share_options'    => $this->shareOptions,
            'sharing_options'  => $this->sharingOptions,
            'create_form'      => $createForm,
        ]);
    }

    public function create(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('create');

        $form = $this->buildShareCreateForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $this->redirectToRoute($this->routes['manage']['name']);
        }

        if (!$form->isValid()) {
            $this->addFlash('error', $this->translator->trans('yopass.error.create', [], YopassBundle::TRANSLATION_DOMAIN));

            return $this->redirectToRoute($this->routes['manage']['name']);
        }

        /** @var ShareCreateData $data */
        $data = $form->getData();

        if ($data->payloadKind === 'file' && !$this->fileSharesEnabled) {
            $this->addFlash('error', $this->translator->trans('yopass.error.file_not_enabled', [], YopassBundle::TRANSLATION_DOMAIN));

            return $this->redirectToRoute($this->routes['manage']['name']);
        }

        $share = $this->shareCreator->create($this->requireUser(), $data);

        return $this->redirectToRoute($this->routes['created']['name'], [
            'id' => $share->getId(),
        ]);
    }

    public function created(string $id): Response
    {
        $this->denyUnlessFeature('create');

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::View)) {
            throw $this->createNotFoundException();
        }

        $publicPath = $this->routes['public_show']['path'];
        $publicPath = str_replace('{id}', $share->getId(), $publicPath);

        return $this->render($this->templates['created'], [
            'share'           => $share,
            'public_path'     => $publicPath,
            'routes'          => $this->routes,
            'sharing_options' => $this->sharingOptions,
            'dashboard_route' => $this->dashboardRoute,
        ]);
    }

    public function preview(string $id): JsonResponse
    {
        $this->denyUnlessFeature('list');

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Preview)) {
            throw $this->createNotFoundException();
        }

        $result = $this->shareRetriever->preview($id);

        if (($result['status'] ?? '') !== 'ok') {
            throw $this->createNotFoundException();
        }

        $publicPath = $this->routes['public_show']['path'];
        $publicPath = str_replace('{id}', $share->getId(), $publicPath);

        $result['publicPath'] = $publicPath;

        if ($this->accessLogger->isEnabled()) {
            $result['accessLog'] = $this->accessLogger->listForShare($share);
        }

        return new JsonResponse($result);
    }

    public function extend(string $id, Request $request): JsonResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfTokenValid('yopass_extend', (string) $request->headers->get('X-CSRF-TOKEN'))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_FORBIDDEN);
        }

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Extend)) {
            throw $this->createNotFoundException();
        }

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];

        $expiresIn = isset($body['expiresIn']) ? trim((string) $body['expiresIn']) : null;
        $maxReads  = isset($body['maxReads']) ? (int) $body['maxReads'] : null;

        if ($expiresIn === '') {
            $expiresIn = null;
        }

        if ($maxReads !== null && $maxReads <= 0) {
            $maxReads = null;
        }

        try {
            $this->shareExtender->extend($share, $expiresIn, $maxReads);
        } catch (ShareExtendException $exception) {
            return new JsonResponse(['error' => $exception->errorCode], Response::HTTP_BAD_REQUEST);
        }

        return new JsonResponse([
            'status'       => 'ok',
            'availability' => $this->shareRetriever->availability($share),
            'maxReads'     => $share->getMaxReads(),
            'readsLeft'    => $share->getReadsLeft(),
            'expiresAt'    => $share->getExpiresAt()->format(DateTimeInterface::ATOM),
        ]);
    }

    public function revoke(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfTokenValid('revoke-share-' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Revoke)) {
            throw $this->createNotFoundException();
        }

        $share->revoke();
        $this->shareRepository->persist($share);
        $this->shareRepository->flush();

        $this->addFlash('success', $this->translator->trans('yopass.revoked', [], YopassBundle::TRANSLATION_DOMAIN));

        return $this->redirectToRoute($this->routes['manage']['name']);
    }

    public function delete(string $id, Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfTokenValid('delete-share-' . $id, (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Delete)) {
            throw $this->createNotFoundException();
        }

        $this->shareRepository->remove($share);
        $this->shareRepository->flush();

        $this->addFlash('success', $this->translator->trans('yopass.deleted', [], YopassBundle::TRANSLATION_DOMAIN));

        return $this->redirectToRoute($this->routes['manage']['name']);
    }

    public function deleteAll(Request $request): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfTokenValid('yopass_delete_all', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $user    = $this->requireUser();
        $removed = $this->shareRepository->removeAllByCreator($user);

        if ($removed > 0) {
            $this->shareRepository->flush();
        }

        $this->addFlash(
            'success',
            $this->translator->trans('yopass.deleted_all', ['%count%' => $removed], YopassBundle::TRANSLATION_DOMAIN),
        );

        return $this->redirectToRoute($this->routes['manage']['name']);
    }

    private function denyUnlessFeature(string $feature): void
    {
        if (!$this->accessChecker->canAccess()) {
            throw $this->createAccessDeniedException($this->translator->trans('yopass.access.denied', [], YopassBundle::TRANSLATION_DOMAIN));
        }

        $allowed = match ($feature) {
            'create' => $this->accessChecker->canCreate(),
            'list'   => $this->accessChecker->canList(),
            'revoke' => $this->accessChecker->canRevoke(),
            default  => false,
        };

        if (!$allowed) {
            throw $this->createAccessDeniedException($this->translator->trans('yopass.feature.denied', [], YopassBundle::TRANSLATION_DOMAIN));
        }
    }

    private function requireUser(): UserInterface
    {
        $user = $this->getUser();

        if (!$user instanceof UserInterface) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function buildShareCreateForm(): FormInterface
    {
        $data            = new ShareCreateData();
        $data->expiresIn = $this->shareOptions['default_expiration'];
        $data->maxReads  = $this->shareOptions['default_max_reads'];

        return $this->createForm(ShareCreateType::class, $data, [
            'share_options'        => $this->shareOptions,
            'max_ciphertext_bytes' => $this->maxCiphertextBytes,
            'file_shares_enabled'  => $this->fileSharesEnabled,
            'action'               => $this->generateUrl($this->routes['create']['name']),
            'method'               => 'POST',
        ]);
    }
}
