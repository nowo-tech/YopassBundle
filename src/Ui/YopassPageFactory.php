<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Ui;

use DateTimeImmutable;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\BackLink;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Confirm;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Service\ShareAccessGuard;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_map;
use function count;
use function is_string;

/**
 * Builds the UiKit view models (`Nowo\UiKitBundle\View\*`, REQ-UI-003) of the Yopass pages. The view models are plain
 * data (labels are `NowoYopassBundle` translation keys, URLs and CSRF-only FormViews are passed in by the
 * controllers), so this service holds configuration only and is safe in worker mode.
 */
final readonly class YopassPageFactory
{
    /** Id of the manage preview / extend `<dialog>` (UiKit `dialog` partial) opened by the View / Extend row actions. */
    public const PREVIEW_DIALOG_ID = 'nowo-yopass-preview';

    /** Id of the create `<dialog>` (`web_ui.create_mode: dialog`) opened by the "New share" header / empty-state actions. */
    public const CREATE_DIALOG_ID = 'nowo-yopass-create-dialog';

    /**
     * Creates the factory on top of the page plumbing (layouts, per-page templates) and the translator of the
     * retention notice's duration.
     */
    public function __construct(
        private YopassPages $pages,
        private ?TranslatorInterface $translator = null,
    ) {
    }

    /**
     * Template of a page (`web_ui.templates.<page>` override, else the bundle template).
     */
    public function template(string $page): string
    {
        return $this->pages->template($page);
    }

    /**
     * Shared variables of the manage pages (1.x layout bridge).
     *
     * @param array<string, mixed> $vars
     *
     * @return array<string, mixed>
     */
    public function context(array $vars): array
    {
        return $this->pages->context($vars);
    }

    /**
     * Manage page (list scaffold): the viewer's shares with View / Extend / Revoke / Delete row actions, the
     * "Delete all" header action, pagination and the empty state. The create form and the preview dialog are rendered
     * by the template around the scaffold regions.
     *
     * @param list<SecureShare> $shares
     * @param array<string, FormView> $revokeForms share id => CSRF-only revoke form
     * @param array<string, FormView> $deleteForms share id => CSRF-only delete form
     * @param array<string, scalar> $attributes root attributes (Stimulus controller of the preview dialog)
     * @param int|null $ownTotal shares the viewer owns ("Delete all" is shown only above 0; null = the row count)
     * @param array<string, array{view: bool, extend: bool, revoke: bool, delete: bool}>|null $rowAccess share id =>
     *                                                                                                   per-row grants from {@see ShareAccessGuard} (null = page-wide flags only)
     * @param bool $createDialog true when `web_ui.create_mode` is `dialog`: "New share" header and empty-state actions
     * @param list<ShareListColumn> $extraColumns host columns appended after the built-in ones
     * @param FormView|null $filters host filter form (ShareListFiltersEvent), rendered in the UiKit filters region
     * @param string|null $filtersClearHref list page without filters (the "clear" link of the filters region)
     */
    public function managePage(
        array $shares,
        ?Pagination $pagination,
        bool $canExtend,
        bool $canRevoke,
        array $revokeForms,
        array $deleteForms,
        ?FormView $deleteAllForm,
        ?string $backHref,
        bool $retentionEnabled,
        string $retentionMaxAge,
        array $attributes = [],
        ?int $ownTotal = null,
        ?array $rowAccess = null,
        bool $createDialog = false,
        array $extraColumns = [],
        ?FormView $filters = null,
        ?string $filtersClearHref = null,
    ): ListPage {
        $headerActions = [];
        // One create action per screen: the header carries it only when the list has rows; an empty list shows it in
        // the empty state alone.
        if ($createDialog && $shares !== []) {
            $headerActions[] = $this->createDialogAction('yopass-create-open');
        }

        if ($canRevoke && $deleteAllForm instanceof FormView && ($ownTotal ?? count($shares)) > 0) {
            $headerActions[] = new Action(
                'delete',
                YopassPages::label('yopass.delete_all'),
                href: $this->formAction($deleteAllForm),
                method: 'POST',
                confirm: new Confirm(
                    YopassPages::label('yopass.delete_all'),
                    YopassPages::label('yopass.delete_all_confirm'),
                    YopassPages::label('yopass.delete_all'),
                ),
                variant: 'danger',
                attributes: ['data-testid' => 'yopass-delete-all'],
                form: $deleteAllForm,
                // UiKit prints the kind's icon on a toolbar action only with an icon or an explicit display.
                display: 'icon_text',
            );
        }

        $rowActions = function (SecureShare $share) use ($canExtend, $canRevoke, $revokeForms, $deleteForms, $rowAccess): array {
            $id      = $share->getId();
            $active  = !$share->getRevokedAt() instanceof DateTimeImmutable;
            $access  = $rowAccess[$id] ?? ($rowAccess === null ? ['view' => true, 'extend' => true, 'revoke' => true, 'delete' => true] : ['view' => false, 'extend' => false, 'revoke' => false, 'delete' => false]);
            $actions = [];
            if ($access['view']) {
                $actions[] = new Action(
                    'view',
                    YopassPages::label('yopass.view'),
                    attributes: ['data-action' => 'nowo-yopass-manage-preview#open', 'data-share-id' => $id],
                    dialogId: self::PREVIEW_DIALOG_ID,
                );
            }
            if ($canExtend && $active && $access['extend']) {
                $actions[] = new Action(
                    'extend',
                    YopassPages::label('yopass.extend.action'),
                    attributes: ['data-action' => 'nowo-yopass-manage-preview#openExtend', 'data-share-id' => $id],
                    dialogId: self::PREVIEW_DIALOG_ID,
                );
            }
            if ($canRevoke && $active && $access['revoke'] && isset($revokeForms[$id])) {
                $actions[] = new Action(
                    'revoke',
                    YopassPages::label('yopass.revoke'),
                    href: $this->formAction($revokeForms[$id]),
                    method: 'POST',
                    confirm: new Confirm(
                        YopassPages::label('yopass.revoke'),
                        YopassPages::label('yopass.revoke_confirm'),
                        YopassPages::label('yopass.revoke'),
                    ),
                    form: $revokeForms[$id],
                );
            }
            if ($canRevoke && $access['delete'] && isset($deleteForms[$id])) {
                $actions[] = Action::delete(
                    $this->formAction($deleteForms[$id]),
                    confirm: Confirm::delete(YopassPages::label('yopass.delete_confirm'), YopassPages::label('yopass.delete')),
                    label: YopassPages::label('yopass.delete'),
                    form: $deleteForms[$id],
                );
            }

            return $actions;
        };

        return new ListPage(
            new PageHeader(
                YopassPages::label('yopass.title'),
                YopassPages::label('yopass.intro'),
                $headerActions,
                back: $backHref !== null ? new BackLink($backHref, YopassPages::label('yopass.back'), 'yopass-back') : null,
            ),
            [
                new Column('payloadKind', YopassPages::label('yopass.list.type')),
                new Column('createdAt', YopassPages::label('yopass.list.created'), priority: 2),
                new Column('expiresAt', YopassPages::label('yopass.list.expires')),
                new Column('readsLeft', YopassPages::label('yopass.list.reads'), priority: 2),
                new Column('status', YopassPages::label('yopass.list.status')),
                ...array_map(
                    static fn (ShareListColumn $column): Column => new Column($column->key, $column->label, priority: $column->priority),
                    $extraColumns,
                ),
            ],
            $shares,
            filters: $filters,
            rowActions: $rowActions,
            pagination: $pagination,
            empty: new EmptyState(
                YopassPages::label('yopass.list.title'),
                YopassPages::label('yopass.list.empty'),
                $createDialog ? $this->createDialogAction('yopass-create-open-empty') : null,
            ),
            filtersClearHref: $filtersClearHref,
            caption: $retentionEnabled
                ? RetentionNotice::label($retentionMaxAge, translator: $this->translator)
                : YopassPages::label('yopass.list.title'),
            bundle: YopassPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'yopass-manage'] + $attributes,
            actionsLabel: YopassPages::label('yopass.list.actions'),
        );
    }

    /**
     * Share created page (show scaffold): back link to the manage page and the "create another" action; the links
     * card (short link, key, optional one-click link) is the `nowo_ui_show_sections` data block.
     *
     * @param array<string, scalar> $attributes root attributes (Stimulus controller of the links card)
     */
    public function createdPage(string $manageHref, array $attributes = []): ShowPage
    {
        return new ShowPage(
            new PageHeader(
                YopassPages::label('yopass.result.title'),
                null,
                [Action::create($manageHref, YopassPages::label('yopass.result.create_another'))],
                back: new BackLink($manageHref, YopassPages::label('yopass.back'), 'yopass-back'),
            ),
            bundle: YopassPages::BUNDLE,
            layout: $this->pages->layout(),
            attributes: ['data-testid' => 'yopass-created'] + $attributes,
        );
    }

    /**
     * Header of the public reveal page (unique page on the guest layout).
     */
    public function publicHeader(): PageHeader
    {
        return new PageHeader(
            YopassPages::label('yopass.public.title'),
            YopassPages::label('yopass.public.intro'),
            breadcrumbsMode: 'own',
        );
    }

    /**
     * Layout of the public reveal page (null = UiKit guest layout).
     */
    public function publicLayout(): ?string
    {
        return $this->pages->publicLayout();
    }

    /**
     * "New share" action opening the create dialog (no href: a button with data-nowo-ui-dialog-open).
     */
    private function createDialogAction(string $testId): Action
    {
        return new Action(
            'share',
            YopassPages::label('yopass.create.open'),
            // No `icon`: UiKit draws the kind (`share`), a canonical icon kind of every icon set.
            variant: 'primary',
            attributes: ['data-testid' => $testId],
            display: 'icon_text',
            dialogId: self::CREATE_DIALOG_ID,
        );
    }

    private function formAction(FormView $form): string
    {
        $action = $form->vars['action'] ?? null;

        return is_string($action) && $action !== '' ? $action : '#';
    }
}
