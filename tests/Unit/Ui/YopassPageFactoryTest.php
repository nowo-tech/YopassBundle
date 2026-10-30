<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Ui;

use DateTimeImmutable;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Nowo\YopassBundle\Ui\YopassPages;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\FormView;

final class YopassPageFactoryTest extends TestCase
{
    public function testManagePageBuildsHeaderColumnsAndRowActions(): void
    {
        $active  = $this->share('00000000-0000-4000-8000-000000000001');
        $revoked = $this->share('00000000-0000-4000-8000-000000000002');
        $revoked->revoke();

        $factory = new YopassPageFactory(new YopassPages(templates: ['manage' => 'host/manage.html.twig']));
        $page    = $factory->managePage(
            shares: [$active, $revoked],
            pagination: new Pagination(1, 2, 3, 2, 'nowo_yopass_index'),
            canExtend: true,
            canRevoke: true,
            revokeForms: [$active->getId() => $this->form('/revoke'), $revoked->getId() => $this->form('/revoke')],
            deleteForms: [$active->getId() => $this->form('/delete'), $revoked->getId() => $this->form('')],
            deleteAllForm: $this->form('/delete-all'),
            backHref: '/dashboard',
            retentionEnabled: true,
            retentionMaxAge: '1 month',
            attributes: ['data-extra' => 'x'],
        );

        self::assertSame('host/manage.html.twig', $factory->template('manage'));
        self::assertSame('NowoYopassBundle', $page->bundle);
        self::assertNull($page->layout);
        self::assertSame(['data-testid' => 'yopass-manage', 'data-extra' => 'x'], $page->attributes);
        self::assertSame('/dashboard', $page->header->back?->href);
        self::assertCount(1, $page->header->actions);
        self::assertSame('/delete-all', $page->header->actions[0]->href);
        self::assertSame('POST', $page->header->actions[0]->method);
        self::assertNotNull($page->header->actions[0]->confirm);
        self::assertInstanceOf(Label::class, $page->caption);
        self::assertSame('yopass.list.retention_notice', $page->caption->key);
        self::assertSame(['payloadKind', 'createdAt', 'expiresAt', 'readsLeft', 'status'], array_map(static fn (Column $column): string => $column->key, $page->columns));

        $activeActions = $page->rowActions($active, 0);
        self::assertSame(['view', 'restore', 'cancel', 'delete'], array_map(static fn (Action $action): string => $action->kind, $activeActions));
        self::assertSame(YopassPageFactory::PREVIEW_DIALOG_ID, $activeActions[0]->dialogId);
        self::assertSame($active->getId(), $activeActions[0]->attributes['data-share-id']);
        self::assertSame('nowo-yopass-manage-preview#openExtend', $activeActions[1]->attributes['data-action']);
        self::assertSame('/revoke', $activeActions[2]->href);
        self::assertSame('/delete', $activeActions[3]->href);

        $revokedActions = $page->rowActions($revoked, 1);
        self::assertSame(['view', 'delete'], array_map(static fn (Action $action): string => $action->kind, $revokedActions));
        self::assertSame('#', $revokedActions[1]->href);
    }

    public function testManagePageWithoutRevokeRightsOrShares(): void
    {
        $share   = $this->share('00000000-0000-4000-8000-000000000001');
        $factory = new YopassPageFactory(new YopassPages());

        $page = $factory->managePage(
            shares: [$share],
            pagination: null,
            canExtend: false,
            canRevoke: false,
            revokeForms: [],
            deleteForms: [],
            deleteAllForm: $this->form('/delete-all'),
            backHref: null,
            retentionEnabled: false,
            retentionMaxAge: '',
        );
        self::assertSame([], $page->header->actions);
        self::assertNull($page->header->back);
        self::assertInstanceOf(Label::class, $page->caption);
        self::assertSame('yopass.list.title', $page->caption->key);
        self::assertSame(['view'], array_map(static fn (Action $action): string => $action->kind, $page->rowActions($share, 0)));

        $empty = $factory->managePage([], null, true, true, [], [], $this->form('/delete-all'), null, false, '');
        self::assertSame([], $empty->header->actions);
        self::assertNotNull($empty->empty);
    }

    public function testCreatedPageAndPublicHeader(): void
    {
        $factory = new YopassPageFactory(new YopassPages(publicLayoutTemplate: 'guest.html.twig'));

        $page = $factory->createdPage('/tools/yopass', ['data-extra' => 'y']);
        self::assertSame('/tools/yopass', $page->header->back?->href);
        self::assertSame('create', $page->header->actions[0]->kind);
        self::assertSame('/tools/yopass', $page->header->actions[0]->href);
        self::assertSame(['data-testid' => 'yopass-created', 'data-extra' => 'y'], $page->attributes);

        $header = $factory->publicHeader();
        self::assertInstanceOf(Label::class, $header->title);
        self::assertSame('yopass.public.title', $header->title->key);
        self::assertSame('own', $header->breadcrumbsMode);
        self::assertSame('guest.html.twig', $factory->publicLayout());
        self::assertSame(['a' => 1, 'nowo_yopass_legacy_layout' => null, 'nowo_yopass_legacy_blocks' => []], $factory->context(['a' => 1]));
    }

    private function share(string $id): SecureShare
    {
        return (new SecureShare($id, new TestUser()))
            ->setCiphertext('REDACTED-TEST')
            ->setExpiresAt(new DateTimeImmutable('2030-01-01 10:00'))
            ->setMaxReads(1);
    }

    private function form(string $action): FormView
    {
        $view                 = new FormView();
        $view->vars['action'] = $action;
        $view->vars['name']   = '';

        return $view;
    }
}
