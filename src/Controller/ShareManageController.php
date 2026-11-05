<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Controller;

use DateTimeInterface;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Event\ShareAccessAction;
use Nowo\YopassBundle\Event\ShareListColumnsEvent;
use Nowo\YopassBundle\Event\ShareListFiltersEvent;
use Nowo\YopassBundle\Event\YopassEvents;
use Nowo\YopassBundle\Exception\ShareExtendException;
use Nowo\YopassBundle\Form\CsrfActionType;
use Nowo\YopassBundle\Form\ShareCreatedLinksType;
use Nowo\YopassBundle\Form\ShareCreateType;
use Nowo\YopassBundle\Form\SharePreviewType;
use Nowo\YopassBundle\Repository\ShareRepositoryInterface;
use Nowo\YopassBundle\Routing\YopassRouteLoader;
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
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Exception\ExceptionInterface as RoutingException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

use function is_array;
use function is_scalar;

/**
 * Authenticated Yopass-style share management (E2E encrypted in the browser).
 *
 * Paths, names, methods, and requirements are declared with {@see Route} attributes (REQ-SF-004).
 * {@see YopassRouteLoader} imports those attributes and applies {@code nowo_yopass.routes} /
 * {@code route_prefix} overrides so hosts need not fork controllers.
 */
final class ShareManageController extends AbstractController
{
    /** Error codes of the JSON create endpoint (same meaning as the page-mode flashes). */
    public const CREATE_ERROR_FAILED = 'CREATE_FAILED';

    public const CREATE_ERROR_FILE_NOT_ENABLED = 'FILE_NOT_ENABLED';

    public const CREATE_ERROR_CROSS_SITE = 'CROSS_SITE';

    /** Name of the host filter form (query `filters[<field>]`). */
    public const FILTERS_FORM = 'filters';

    private readonly ShareDeleter $shareDeleter;

    private readonly ShareRevoker $shareRevoker;

    private readonly ShareListRouteResolver $listRoutes;

    /**
     * Creates the manage controller with routes, templates, limits and share options.
     *
     * @param array<string, array{path: string, name: string}> $routes
     * @param array{
     *     default_expiration: string,
     *     default_max_reads: int,
     *     max_reads_options: list<int>,
     *     expiration_options: list<array{id: string, interval: string}>,
     *     list_page_size?: int,
     *     retention?: array{enabled: bool, max_age: string}
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
        private readonly YopassPageFactory $pages,
        private readonly int $maxCiphertextBytes,
        private readonly int $maxSecretChars,
        private readonly ?string $dashboardRoute,
        private readonly array $shareOptions,
        private readonly array $sharingOptions,
        private readonly bool $fileSharesEnabled,
        private readonly ?ShareFileHandlerInterface $fileHandler = null,
        ?ShareDeleter $shareDeleter = null,
        ?ShareRevoker $shareRevoker = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly string $createMode = 'page',
        ?ShareListRouteResolver $listRoutes = null,
    ) {
        $this->shareDeleter = $shareDeleter ?? new ShareDeleter($shareRepository);
        $this->shareRevoker = $shareRevoker ?? new ShareRevoker($shareRepository);
        $this->listRoutes   = $listRoutes ?? new ShareListRouteResolver($routes['manage']['name']);
    }

    /**
     * Renders the manage page with the create form and the viewer's shares.
     */
    #[Route('/tools/yopass', name: 'nowo_yopass_index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->denyUnlessFeature('list');

        $user = $this->requireUser();

        $this->retentionPurger->purgeForCreator($user);

        // The page showing the list (a host page through SHARE_LIST_ROUTE) and the host's filter fields.
        $listRoute                             = $this->listRoutes->resolve($user, $request);
        $listToken                             = $this->listRoutes->sign($user, $listRoute);
        $listHref                              = $this->generateUrl($listRoute->name, $listRoute->params);
        [$filtersForm, $filters, $filterQuery] = $this->buildFilters($user, $request, $listHref);

        $pageSize   = (int) ($this->shareOptions['list_page_size'] ?? 0);
        $page       = max(1, $request->query->getInt('page', 1));
        $list       = $this->shareLister->list($user, $pageSize, $page, $filters);
        $shares     = $list['shares'];
        $total      = $list['total'];
        $totalPages = $pageSize > 0 ? (int) max(1, ceil($total / $pageSize)) : 1;
        $page       = min($page, $totalPages);

        $canCreate  = $this->accessChecker->canCreate($user);
        $createForm = null;
        if ($canCreate) {
            $createForm = $this->buildShareCreateForm()->createView();
        }

        $createDialog = $this->createMode === 'dialog' && $createForm instanceof FormView;

        $canRevoke = $this->accessChecker->canRevoke($user);
        $canExtend = $canRevoke;
        /** @var array<string, array{view: bool, extend: bool, revoke: bool, delete: bool}> $rowAccess */
        $rowAccess = [];
        /** @var array<string, FormView> $revokeForms */
        $revokeForms = [];
        /** @var array<string, FormView> $deleteForms */
        $deleteForms = [];
        foreach ($shares as $share) {
            // Per-row grants (SHARE_ACCESS_CHECK), computed once per row and action for this request; the routes keep
            // their own server-side checks.
            $shareId             = (string) $share->getId();
            $rowAccess[$shareId] = [
                'view'   => $this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Preview),
                'extend' => $canExtend && $this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Extend),
                'revoke' => $canRevoke && $this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Revoke),
                'delete' => $canRevoke && $this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Delete),
            ];
            if ($rowAccess[$shareId]['revoke']) {
                $revokeForms[$shareId] = $this->createCsrfActionForm($this->routes['revoke']['name'], 'revoke-share-' . $shareId, $shareId, $listToken)->createView();
            }
            if ($rowAccess[$shareId]['delete']) {
                $deleteForms[$shareId] = $this->createCsrfActionForm($this->routes['delete']['name'], 'delete-share-' . $shareId, $shareId, $listToken)->createView();
            }
        }

        $columnsEvent = new ShareListColumnsEvent($user, $shares);
        $this->eventDispatcher?->dispatch($columnsEvent, YopassEvents::SHARE_LIST_COLUMNS);
        $extraColumns = $columnsEvent->getColumns();
        /** @var array<string, array<string, string|null>> $extraCells */
        $extraCells = [];
        foreach ($shares as $share) {
            foreach ($extraColumns as $column) {
                $extraCells[(string) $share->getId()][$column->key] = $column->valueFor($share);
            }
        }

        $deleteAllForm = $this->createCsrfActionForm($this->routes['delete_all']['name'], 'yopass_delete_all', null, $listToken)->createView();
        $retention     = $this->shareOptions['retention'] ?? [];

        $listPage = $this->pages->managePage(
            shares: $shares,
            pagination: $pageSize > 0 && $totalPages > 1
                ? new Pagination($page, $pageSize, $total, $totalPages, $listRoute->name, $filterQuery, $listRoute->params)
                : null,
            canExtend: $canExtend,
            canRevoke: $canRevoke,
            revokeForms: $revokeForms,
            deleteForms: $deleteForms,
            deleteAllForm: $deleteAllForm,
            backHref: $this->dashboardRoute !== null && $this->dashboardRoute !== '' ? $this->generateUrl($this->dashboardRoute) : null,
            retentionEnabled: (bool) ($retention['enabled'] ?? false),
            retentionMaxAge: (string) ($retention['max_age'] ?? ''),
            ownTotal: $list['own_total'],
            rowAccess: $rowAccess,
            createDialog: $createDialog,
            extraColumns: $extraColumns,
            filters: $filtersForm,
            filtersClearHref: $filtersForm instanceof FormView ? $listHref : null,
        );

        // REQ-UI-003: `page` (View\ListPage) feeds the list scaffold; the other variables stay for web_ui.templates
        // overrides and the create form / preview dialog regions of the template.
        return $this->render($this->pages->template('manage'), $this->pages->context([
            'page'           => $listPage,
            'shares'         => $shares,
            'shares_total'   => $total,
            'shares_page'    => $page,
            'shares_pages'   => $totalPages,
            'list_page_size' => $pageSize,
            'can_create'     => $canCreate,
            'create_mode'    => $createDialog ? 'dialog' : 'page',
            'row_access'     => $rowAccess,
            'extra_columns'  => $extraColumns,
            'extra_cells'    => $extraCells,
            'own_total'      => $list['own_total'],
            'list_route'     => $listRoute,
            'list_filters'   => $filters,
            'links_form'     => $createDialog ? $this->createForm(ShareCreatedLinksType::class, null, [
                'allow_embed_in_url' => $this->sharingOptions['allow_embed_in_url'],
                'target_controller'  => 'nowo-yopass-create',
                'id_prefix'          => 'yopass-create-result',
            ])->createView() : null,
            'can_revoke'       => $canRevoke,
            'can_extend'       => $canExtend,
            'can_share_file'   => $this->fileSharesEnabled,
            'max_file_bytes'   => $this->fileHandler?->getMaxFileBytes() ?? 0,
            'max_secret_chars' => $this->maxSecretChars,
            'dashboard_route'  => $this->dashboardRoute,
            'routes'           => $this->routes,
            'share_options'    => $this->shareOptions,
            'sharing_options'  => $this->sharingOptions,
            'create_form'      => $createForm,
            'revoke_forms'     => $revokeForms,
            'delete_forms'     => $deleteForms,
            'delete_all_form'  => $deleteAllForm,
            'preview_form'     => $this->createForm(SharePreviewType::class, null, [
                'share_options'  => $this->shareOptions,
                'extend_enabled' => $canRevoke,
            ])->createView(),
        ]));
    }

    /**
     * Stores a client-encrypted share submitted from the create form.
     *
     * Page mode: POST + redirect to the created page (errors as flashes). JSON mode (the dialog's fetch, `Accept:
     * application/json`): `201 {id, public_path, created_url}` or `422 {error}` (codes CREATE_FAILED / FILE_NOT_ENABLED),
     * `403 {error: CROSS_SITE}` when `Sec-Fetch-Site` is present and not `same-origin` (page mode: a plain 403), never a
     * flash. The JSON never contains the ciphertext, a key or
     * plaintext: the browser keeps the key in memory and builds the links itself. Same CSRF token in both modes.
     */
    #[Route('/tools/yopass/create', name: 'nowo_yopass_create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->denyUnlessFeature('create');

        $json = $this->wantsJson($request);

        // Fetch metadata (both modes): a browser that reports a non same-origin request is refused before the form is
        // handled; a missing header (older browsers, non-browser clients) relies on the CSRF token alone.
        if (!$this->isSameOriginFetch($request)) {
            if ($json) {
                return $this->jsonError(self::CREATE_ERROR_CROSS_SITE, Response::HTTP_FORBIDDEN);
            }

            throw $this->createAccessDeniedException();
        }

        $form = $this->buildShareCreateForm();
        $form->handleRequest($request);

        if (!$form->isSubmitted()) {
            return $json
                ? $this->jsonError(self::CREATE_ERROR_FAILED, Response::HTTP_UNPROCESSABLE_ENTITY)
                : $this->redirectToRoute($this->routes['manage']['name']);
        }

        if (!$form->isValid()) {
            return $this->createFailed($json, self::CREATE_ERROR_FAILED, 'yopass.error.create');
        }

        /** @var ShareCreateData $data */
        $data = $form->getData();

        if ($data->payloadKind === 'file' && !$this->fileSharesEnabled) {
            return $this->createFailed($json, self::CREATE_ERROR_FILE_NOT_ENABLED, 'yopass.error.file_not_enabled');
        }

        $share = $this->shareCreator->create($this->requireUser(), $data);

        if ($json) {
            // Zero-knowledge: ids and paths only (no ciphertext echo, no key, no plaintext).
            return PublicShareController::hardenSecretResponse(new JsonResponse([
                'id'          => $share->getId(),
                'public_path' => $this->publicSharePath($share),
                'created_url' => $this->generateUrl($this->routes['created']['name'], ['id' => $share->getId()]),
            ], Response::HTTP_CREATED));
        }

        return $this->redirectToRoute($this->routes['created']['name'], [
            'id' => $share->getId(),
        ]);
    }

    /**
     * Renders the page shown after creating a share (links and key handling).
     */
    #[Route('/tools/yopass/{id}/created', name: 'nowo_yopass_created', methods: ['GET'])]
    public function created(string $id): Response
    {
        $this->denyUnlessFeature('create');

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::View)) {
            throw $this->createNotFoundException();
        }

        $publicPath = $this->publicSharePath($share);

        $manageHref = $this->generateUrl($this->routes['manage']['name']);

        $response = $this->render($this->pages->template('created'), $this->pages->context([
            'page'            => $this->pages->createdPage($manageHref),
            'share'           => $share,
            'public_path'     => $publicPath,
            'routes'          => $this->routes,
            'sharing_options' => $this->sharingOptions,
            'dashboard_route' => $this->dashboardRoute,
            'links_form'      => $this->createForm(ShareCreatedLinksType::class, null, [
                'allow_embed_in_url' => $this->sharingOptions['allow_embed_in_url'],
            ])->createView(),
        ]));

        // REQ-SEC-004: the created page holds the share links (and possibly the embedded key).
        return PublicShareController::hardenSecretResponse($response);
    }

    /**
     * Returns share metadata as JSON for the manage preview (never the plaintext).
     */
    #[Route('/tools/yopass/{id}/preview', name: 'nowo_yopass_preview', methods: ['GET'])]
    public function preview(string $id): JsonResponse
    {
        $this->denyUnlessFeature('list');

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Preview)) {
            throw $this->createNotFoundException();
        }

        $result = $this->shareRetriever->preview($id);

        if ($result['status'] !== 'ok') {
            throw $this->createNotFoundException();
        }

        $result['publicPath'] = $this->publicSharePath($share);

        if ($this->accessLogger->isEnabled()) {
            $result['accessLog'] = $this->accessLogger->listForShare($share);
        }

        return new JsonResponse($result);
    }

    /**
     * Extends the expiration and/or max reads of a share (CSRF-protected JSON endpoint).
     */
    #[Route('/tools/yopass/{id}/extend', name: 'nowo_yopass_extend', methods: ['POST'])]
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
            $this->shareExtender->extend($share, $expiresIn, $maxReads, $user);
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

    /**
     * Revokes a share so it can no longer be read.
     */
    #[Route('/tools/yopass/{id}/revoke', name: 'nowo_yopass_revoke', methods: ['POST'])]
    public function revoke(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfActionValid($this->createCsrfActionForm($this->routes['revoke']['name'], 'revoke-share-' . $id, $id), $request)) {
            throw $this->createAccessDeniedException();
        }

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Revoke)) {
            throw $this->createNotFoundException();
        }

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $this->shareRevoker->revoke($share, $user);

        $this->addFlash('success', $this->translator->trans('yopass.revoked', [], YopassBundle::TRANSLATION_DOMAIN));

        return $this->redirectToList($user, $request);
    }

    /**
     * Deletes a share owned by the viewer.
     */
    #[Route('/tools/yopass/{id}/delete', name: 'nowo_yopass_delete', methods: ['POST'])]
    public function delete(string $id, Request $request): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfActionValid($this->createCsrfActionForm($this->routes['delete']['name'], 'delete-share-' . $id, $id), $request)) {
            throw $this->createAccessDeniedException();
        }

        $user  = $this->requireUser();
        $share = $this->shareRepository->find($id);

        if (!$share instanceof SecureShare || !$this->shareAccessGuard->canManage($user, $share, ShareAccessAction::Delete)) {
            throw $this->createNotFoundException();
        }

        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $this->shareDeleter->delete($share, $user);

        $this->addFlash('success', $this->translator->trans('yopass.deleted', [], YopassBundle::TRANSLATION_DOMAIN));

        return $this->redirectToList($user, $request);
    }

    /**
     * Deletes every share owned by the viewer (scoped / vetoed by {@see YopassEvents::SHARE_DELETE_ALL}).
     */
    #[Route('/tools/yopass/delete-all', name: 'nowo_yopass_delete_all', methods: ['POST'])]
    public function deleteAll(Request $request): RedirectResponse
    {
        $this->denyUnlessFeature('revoke');

        if (!$this->isCsrfActionValid($this->createCsrfActionForm($this->routes['delete_all']['name'], 'yopass_delete_all'), $request)) {
            throw $this->createAccessDeniedException();
        }

        $user = $this->requireUser();
        // @igor-ignore - HTTP handler delegates to services; no controller worker state.
        $removed = $this->shareDeleter->deleteAll($user);

        if ($removed === null) {
            // A nowo_yopass.share_delete_all listener vetoed the removal.
            $this->addFlash('error', $this->translator->trans('yopass.error.delete_all_denied', [], YopassBundle::TRANSLATION_DOMAIN));

            return $this->redirectToList($user, $request);
        }

        $this->addFlash(
            'success',
            $this->translator->trans('yopass.deleted_all', ['%count%' => $removed], YopassBundle::TRANSLATION_DOMAIN),
        );

        return $this->redirectToList($user, $request);
    }

    /**
     * Whether the request is the dialog's fetch (`Accept: application/json` first).
     */
    private function wantsJson(Request $request): bool
    {
        return ($request->getAcceptableContentTypes()[0] ?? null) === 'application/json';
    }

    /**
     * Fetch metadata check: a browser that sends `Sec-Fetch-Site` must report a same-origin request (defence in depth
     * on top of the CSRF token). Requests without the header (older browsers, tests) rely on the CSRF token.
     */
    private function isSameOriginFetch(Request $request): bool
    {
        $site = $request->headers->get('Sec-Fetch-Site');

        return $site === null || $site === 'same-origin';
    }

    private function jsonError(string $code, int $status): Response
    {
        return PublicShareController::hardenSecretResponse(new JsonResponse(['error' => $code], $status));
    }

    private function createFailed(bool $json, string $code, string $flashKey): Response
    {
        if ($json) {
            return $this->jsonError($code, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('error', $this->translator->trans($flashKey, [], YopassBundle::TRANSLATION_DOMAIN));

        return $this->redirectToRoute($this->routes['manage']['name']);
    }

    /**
     * Public share path generated through the router, so `route_prefix`, extra route placeholders
     * (e.g. `{_locale}`) and the request base path are honoured. The browser prepends its origin.
     */
    private function publicSharePath(SecureShare $share): string
    {
        return $this->generateUrl($this->routes['public_show']['name'], ['id' => $share->getId()]);
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

    /**
     * CSRF-only row / toolbar action form (flat {@code _token}, REQ-TWIG-005).
     *
     * @return FormInterface<mixed>
     */
    private function createCsrfActionForm(string $route, string $csrfTokenId, ?string $id = null, ?string $listToken = null): FormInterface
    {
        return $this->createForm(CsrfActionType::class, null, [
            'action'        => $this->generateUrl($route, $id === null ? [] : ['id' => $id]),
            'csrf_token_id' => $csrfTokenId,
            'list_token'    => $listToken,
        ]);
    }

    /**
     * Redirect after revoke / delete / delete all: back to the list page the action came from. The posted value is a
     * token signed for this viewer and checked against the list-route allow-list (ShareListRouteResolver); the URL is
     * generated by the router. Missing, forged, foreign or not-allowed tokens fall back to the manage route.
     */
    private function redirectToList(UserInterface $user, Request $request): RedirectResponse
    {
        $route = $this->listRoutes->fromToken($user, $request->request->get(ShareListRouteResolver::FIELD));

        try {
            return $this->redirectToRoute($route->name, $route->params);
        } catch (RoutingException) {
            // The host route needs other parameters / no longer exists: stay safe on the manage page.
            return $this->redirectToRoute($this->routes['manage']['name']);
        }
    }

    /**
     * GET filter form of the host's filter fields ({@see YopassEvents::SHARE_LIST_FILTERS}); null when no listener
     * added a field. Returns the form view, the submitted data (for ShareListQueryEvent) and the query to keep across
     * pages.
     *
     * @return array{0: FormView|null, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function buildFilters(UserInterface $user, Request $request, string $listHref): array
    {
        $builder = $this->container->get('form.factory')->createNamedBuilder(self::FILTERS_FORM, FormType::class, null, [
            'method'             => 'GET',
            'action'             => $listHref,
            'csrf_protection'    => false,
            'allow_extra_fields' => true,
            'translation_domain' => YopassBundle::TRANSLATION_DOMAIN,
        ]);
        $this->eventDispatcher?->dispatch(new ShareListFiltersEvent($user, $builder), YopassEvents::SHARE_LIST_FILTERS);

        if ($builder->count() === 0) {
            return [null, [], []];
        }

        $form = $builder->getForm();
        $form->handleRequest($request);
        /** @var array<string, mixed> $data */
        $data = $form->isSubmitted() && $form->isValid() ? (array) $form->getData() : [];

        $query = $request->query->all(self::FILTERS_FORM);
        $query = $query !== [] ? [self::FILTERS_FORM => self::scalarTree($query)] : [];

        return [$form->createView(), $data, $query];
    }

    /**
     * Keeps only scalar leaves (strings from the query string) of the filter query carried by the pager.
     *
     * @param array<mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function scalarTree(array $values): array
    {
        $clean = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $clean[(string) $key] = self::scalarTree($value);
            } elseif (is_scalar($value)) {
                $clean[(string) $key] = $value;
            }
        }

        return $clean;
    }

    /**
     * A nameless CSRF-only form has no children, so handleRequest() never auto-submits it: submit the flat POST
     * body explicitly and let the CSRF listener validate {@code _token}.
     *
     * @param FormInterface<mixed> $form
     */
    private function isCsrfActionValid(FormInterface $form, Request $request): bool
    {
        $form->submit($request->request->all());

        return $form->isValid();
    }

    /**
     * @return FormInterface<ShareCreateData>
     */
    private function buildShareCreateForm(): FormInterface
    {
        $data            = new ShareCreateData();
        $data->expiresIn = $this->shareOptions['default_expiration'];
        $data->maxReads  = $this->shareOptions['default_max_reads'];

        return $this->createForm(ShareCreateType::class, $data, [
            'share_options'         => $this->shareOptions,
            'max_ciphertext_bytes'  => $this->maxCiphertextBytes,
            'file_shares_enabled'   => $this->fileSharesEnabled,
            'allow_custom_password' => $this->sharingOptions['allow_custom_password'],
            'default_encryption'    => $this->sharingOptions['default_encryption'],
            'action'                => $this->generateUrl($this->routes['create']['name']),
            'method'                => 'POST',
        ]);
    }
}
