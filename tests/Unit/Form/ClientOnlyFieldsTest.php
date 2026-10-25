<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Form;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Form\CsrfActionType;
use Nowo\YopassBundle\Form\ShareCreatedLinksType;
use Nowo\YopassBundle\Form\ShareCreateType;
use Nowo\YopassBundle\Form\SharePreviewType;
use Nowo\YopassBundle\Form\ShareRevealType;
use Nowo\YopassBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * REQ-TWIG-005: browser-only controls keep their ids / Stimulus targets and are never posted.
 */
final class ClientOnlyFieldsTest extends TestCase
{
    private const SHARE_OPTIONS = [
        'default_expiration' => '1h',
        'default_max_reads'  => 1,
        'max_reads_options'  => [1, 3],
        'expiration_options' => [['id' => '1h', 'interval' => '1 hour'], ['id' => '1d', 'interval' => '1 day']],
    ];

    public function testShareCreateClientOnlyChildrenAreNamelessAndKeepIds(): void
    {
        $view = $this->factory()->create(ShareCreateType::class, new ShareCreateData(), [
            'share_options'         => self::SHARE_OPTIONS,
            'file_shares_enabled'   => true,
            'allow_custom_password' => true,
            'default_encryption'    => 'password',
        ])->createView();

        self::assertFalse($view->vars['multipart']);
        self::assertSame('yopass-secret', $view['secret']->vars['id']);
        self::assertSame('', $view['secret']->vars['full_name']);
        self::assertSame('secret', $view['secret']->vars['attr']['data-nowo-yopass-create-target']);
        self::assertSame('yopass-file', $view['file']->vars['id']);
        self::assertSame('', $view['file']->vars['full_name']);
        self::assertSame('yopass-custom-password', $view['customPassword']->vars['id']);
        self::assertSame('', $view['customPassword']->vars['full_name']);

        $modes = [];
        foreach ($view['encryptionMode'] as $radio) {
            $modes[$radio->vars['value']] = $radio;
        }
        self::assertSame(ShareCreateType::ENCRYPTION_MODE_NAME, $modes['auto']->vars['full_name']);
        self::assertSame('yopass-encryption-auto', $modes['auto']->vars['id']);
        self::assertSame('yopass-encryption-password', $modes['password']->vars['id']);
        self::assertTrue($modes['password']->vars['checked']);
        self::assertSame('encryptionPassword', $modes['password']->vars['attr']['data-nowo-yopass-create-target']);
    }

    public function testShareCreateWithoutFileOrCustomPassword(): void
    {
        $form = $this->factory()->create(ShareCreateType::class, new ShareCreateData(), [
            'share_options'         => self::SHARE_OPTIONS,
            'allow_custom_password' => false,
            'default_encryption'    => 'password',
        ]);

        self::assertFalse($form->has('file'));
        self::assertFalse($form->has('customPassword'));
        self::assertSame('auto', $form->get('encryptionMode')->getData());
        self::assertCount(1, $form->get('encryptionMode'));
    }

    public function testPreviewCreatedAndRevealTypes(): void
    {
        $factory = $this->factory();

        $preview = $factory->create(SharePreviewType::class, null, ['share_options' => self::SHARE_OPTIONS, 'extend_enabled' => true])->createView();
        self::assertSame('yopass-preview-password', $preview['password']->vars['id']);
        self::assertSame('', $preview['key']->vars['full_name']);
        self::assertSame('yopass-extend-expires', $preview['extendExpiresIn']->vars['id']);
        self::assertCount(2, $preview['extendMaxReads']->vars['choices']);

        $noExtend = $factory->create(SharePreviewType::class)->createView();
        self::assertArrayNotHasKey('extendExpiresIn', $noExtend->children);

        $links = $factory->create(ShareCreatedLinksType::class)->createView();
        self::assertSame('yopass-created-one-click', $links['oneClickLink']->vars['id']);
        self::assertSame('resultKey', $links['key']->vars['attr']['data-nowo-yopass-created-target']);

        $password = $factory->create(ShareRevealType::class, null, ['decryption_mode' => 'password'])->createView();
        self::assertSame('share-password', $password['password']->vars['id']);
        self::assertSame('', $password['password']->vars['full_name']);

        $key = $factory->create(ShareRevealType::class)->createView();
        self::assertSame('share-key', $key['key']->vars['id']);
    }

    public function testCsrfActionTypeIsNamelessWithoutFields(): void
    {
        $form = $this->factory()->create(CsrfActionType::class, null, ['csrf_token_id' => 'yopass_delete_all']);

        self::assertSame('', $form->getName());
        self::assertCount(0, $form);
    }

    private function factory(): FormFactoryInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return Forms::createFormFactoryBuilder()
            ->addExtensions([new PreloadedExtension([
                FormKitTestSupport::withMerger(new ShareCreateType($translator)),
                FormKitTestSupport::withMerger(new CsrfActionType()),
                FormKitTestSupport::withMerger(new SharePreviewType()),
                FormKitTestSupport::withMerger(new ShareCreatedLinksType()),
                FormKitTestSupport::withMerger(new ShareRevealType()),
            ], [])])
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory();
    }
}
