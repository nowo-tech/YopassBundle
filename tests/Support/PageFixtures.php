<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Support;

use DateTimeImmutable;
use Nowo\UiKitBundle\View\Pagination;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Entity\SecureShare;
use Nowo\YopassBundle\Form\CsrfActionType;
use Nowo\YopassBundle\Form\ShareCreatedLinksType;
use Nowo\YopassBundle\Form\ShareCreateType;
use Nowo\YopassBundle\Form\SharePreviewType;
use Nowo\YopassBundle\Form\ShareRevealType;
use Nowo\YopassBundle\Tests\Stub\TestUser;
use Nowo\YopassBundle\Ui\YopassPageFactory;
use Nowo\YopassBundle\Ui\YopassPages;
use Symfony\Component\Form\Extension\Csrf\CsrfExtension;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\FormView;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;
use Symfony\Component\Validator\Validation;

use function dirname;
use function glob;

/**
 * Page fixtures of the REQ-UI-003 contract test: the view models and FormViews the controllers pass to each page.
 * Obvious placeholder values only (no realistic secrets).
 */
final class PageFixtures
{
    private const ROUTES = [
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

    /**
     * Manage page variables (list scaffold + create panel + preview dialog).
     *
     * @return array<string, mixed>
     */
    public static function manage(bool $empty = false, bool $fileShares = true): array
    {
        $user   = new TestUser();
        $shares = [];
        if (!$empty) {
            $active = (new SecureShare('00000000-0000-4000-8000-000000000001', $user))
                ->setCiphertext('REDACTED-TEST')->setExpiresAt(new DateTimeImmutable('2030-01-01 10:00'))->setMaxReads(3);
            $file = (new SecureShare('00000000-0000-4000-8000-000000000002', $user))
                ->setCiphertext('REDACTED-TEST')->setExpiresAt(new DateTimeImmutable('2030-01-02 10:00'))->setMaxReads(1)
                ->setPayloadKind('file');
            $revoked = (new SecureShare('00000000-0000-4000-8000-000000000003', $user))
                ->setCiphertext('REDACTED-TEST')->setExpiresAt(new DateTimeImmutable('2030-01-03 10:00'))->setMaxReads(1);
            $revoked->revoke();
            $expired = (new SecureShare('00000000-0000-4000-8000-000000000004', $user))
                ->setCiphertext('REDACTED-TEST')->setExpiresAt(new DateTimeImmutable('2000-01-01 10:00'))->setMaxReads(1);
            $shares = [$active, $file, $revoked, $expired];
        }

        $forms       = self::formFactory();
        $revokeForms = [];
        $deleteForms = [];
        foreach ($shares as $share) {
            $id               = $share->getId();
            $revokeForms[$id] = self::csrfForm($forms, '/tools/yopass/' . $id . '/revoke', 'revoke-share-' . $id);
            $deleteForms[$id] = self::csrfForm($forms, '/tools/yopass/' . $id . '/delete', 'delete-share-' . $id);
        }
        $deleteAll = self::csrfForm($forms, '/tools/yopass/delete-all', 'yopass_delete_all');

        $page = self::factory()->managePage(
            shares: $shares,
            pagination: $empty ? null : new Pagination(1, 4, 9, 3, 'nowo_yopass_index'),
            canExtend: true,
            canRevoke: true,
            revokeForms: $revokeForms,
            deleteForms: $deleteForms,
            deleteAllForm: $deleteAll,
            backHref: '/dashboard',
            retentionEnabled: !$empty,
            retentionMaxAge: '1 month',
        );

        return [
            'page'             => $page,
            'shares'           => $shares,
            'can_create'       => true,
            'can_revoke'       => true,
            'can_extend'       => true,
            'can_share_file'   => $fileShares,
            'max_file_bytes'   => 1024,
            'max_secret_chars' => 512,
            'dashboard_route'  => 'demo_home',
            'routes'           => self::ROUTES,
            'share_options'    => DefaultShareOptions::get(),
            'sharing_options'  => self::sharingOptions(),
            'create_form'      => $forms->create(ShareCreateType::class, self::createData(), [
                'share_options'         => DefaultShareOptions::get(),
                'file_shares_enabled'   => $fileShares,
                'allow_custom_password' => true,
                'action'                => '/tools/yopass/create',
            ])->createView(),
            'revoke_forms'    => $revokeForms,
            'delete_forms'    => $deleteForms,
            'delete_all_form' => $deleteAll,
            'preview_form'    => $forms->create(SharePreviewType::class, null, [
                'share_options'  => DefaultShareOptions::get(),
                'extend_enabled' => true,
            ])->createView(),
        ];
    }

    /**
     * Share created page variables (show scaffold + links card).
     *
     * @return array<string, mixed>
     */
    public static function created(bool $embed = true, bool $embedFirst = false): array
    {
        $share = (new SecureShare('00000000-0000-4000-8000-000000000001', new TestUser()))
            ->setCiphertext('REDACTED-TEST')->setExpiresAt(new DateTimeImmutable('2030-01-01 10:00'))->setMaxReads(1);

        return [
            'page'            => self::factory()->createdPage('/tools/yopass'),
            'share'           => $share,
            'public_path'     => '/share/00000000-0000-4000-8000-000000000001',
            'routes'          => self::ROUTES,
            'sharing_options' => ['allow_embed_in_url' => $embed, 'default_embed_in_url' => $embedFirst] + self::sharingOptions(),
            'dashboard_route' => null,
            'links_form'      => self::formFactory()->create(ShareCreatedLinksType::class, null, [
                'allow_embed_in_url' => $embed,
            ])->createView(),
        ];
    }

    /**
     * Public reveal page variables (unique page on the guest layout).
     *
     * @return array<string, mixed>
     */
    public static function reveal(string $mode = 'key', bool $available = true): array
    {
        $share = (new SecureShare('00000000-0000-4000-8000-000000000001', new TestUser()))
            ->setCiphertext('REDACTED-TEST')
            ->setExpiresAt(new DateTimeImmutable($available ? '2030-01-01 10:00' : '2000-01-01 10:00'))
            ->setMaxReads(1);

        return [
            'header'          => self::factory()->publicHeader(),
            'page_layout'     => null,
            'share'           => $share,
            'decryption_mode' => $mode,
            'routes'          => self::ROUTES,
            'reveal_form'     => self::formFactory()->create(ShareRevealType::class, null, ['decryption_mode' => $mode])->createView(),
        ];
    }

    private static function createData(): ShareCreateData
    {
        $data            = new ShareCreateData();
        $data->expiresIn = '1h';
        $data->maxReads  = 1;

        return $data;
    }

    private static function factory(): YopassPageFactory
    {
        return new YopassPageFactory(new YopassPages());
    }

    /**
     * @return array{default_encryption: string, allow_custom_password: bool, default_embed_in_url: bool, allow_embed_in_url: bool, show_remember_notice: bool}
     */
    private static function sharingOptions(): array
    {
        return [
            'default_encryption'    => 'auto',
            'allow_custom_password' => true,
            'default_embed_in_url'  => false,
            'allow_embed_in_url'    => true,
            'show_remember_notice'  => true,
        ];
    }

    private static function csrfForm(FormFactoryInterface $forms, string $action, string $tokenId): FormView
    {
        return $forms->create(CsrfActionType::class, null, ['action' => $action, 'csrf_token_id' => $tokenId])->createView();
    }

    private static function formFactory(): FormFactoryInterface
    {
        $tokens = new class implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'REDACTED-TEST-TOKEN');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return $this->getToken($tokenId);
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return Forms::createFormFactoryBuilder()
            ->addExtension(new CsrfExtension($tokens))
            ->addExtension(new ValidatorExtension(Validation::createValidatorBuilder()->getValidator()))
            ->addExtension(new PreloadedExtension([
                FormKitTestSupport::withMerger(new ShareCreateType(self::translator())),
                FormKitTestSupport::withMerger(new CsrfActionType()),
                FormKitTestSupport::withMerger(new SharePreviewType()),
                FormKitTestSupport::withMerger(new ShareCreatedLinksType()),
                FormKitTestSupport::withMerger(new ShareRevealType()),
            ], []))
            ->getFormFactory();
    }

    private static function translator(): Translator
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        foreach (glob(dirname(__DIR__, 2) . '/src/Resources/translations/NowoYopassBundle.en.yaml') ?: [] as $file) {
            $translator->addResource('yaml', $file, 'en', 'NowoYopassBundle');
        }

        return $translator;
    }
}
