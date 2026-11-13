<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Form;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\Form\ShareCreateType;
use Nowo\YopassBundle\Tests\Support\FormKitTestSupport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Extension\Validator\ValidatorExtension;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\Forms;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Validator\Validation;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ShareCreateTypeTest extends TestCase
{
    public function testPreSubmitFillsMissingCiphertextWithEmptyString(): void
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formType = FormKitTestSupport::withMerger(new ShareCreateType($translator));
        $form     = Forms::createFormFactoryBuilder()
            ->addExtensions([new PreloadedExtension([$formType], [])])
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->create(ShareCreateType::class, new ShareCreateData(), [
                'share_options' => [
                    'default_expiration' => '1h',
                    'default_max_reads'  => 1,
                    'max_reads_options'  => [1, 3, 10],
                    'expiration_options' => [
                        ['id' => '1h', 'interval' => '1 hour'],
                    ],
                ],
                'max_ciphertext_bytes' => 1024,
            ]);

        $form->submit([
            'expiresIn'   => '1h',
            'maxReads'    => 1,
            'payloadKind' => 'text',
        ]);

        self::assertInstanceOf(ShareCreateData::class, $form->getData());
        self::assertSame('', $form->get('ciphertext')->getData());
    }

    /**
     * @return iterable<string, array{0: mixed}>
     */
    public static function submissionsWithoutUsableFields(): iterable
    {
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'unknown field only' => [['unknown' => 'x']];
        yield 'empty choices' => [['expiresIn' => '', 'maxReads' => '', 'payloadKind' => '']];
    }

    #[DataProvider('submissionsWithoutUsableFields')]
    public function testSubmissionWithoutChoicesFallsBackToTheDefaultsInsteadOfThrowing(mixed $submitted): void
    {
        $data = new ShareCreateData();
        $form = $this->createForm($data);

        $form->submit($submitted);

        // Rejected by the ciphertext constraint, never by a TypeError while mapping null onto the DTO.
        self::assertFalse($form->isValid());
        self::assertSame('1h', $data->expiresIn);
        self::assertSame(1, $data->maxReads);
        self::assertSame('text', $data->payloadKind);
    }

    public function testScalarSubmissionIsLeftAloneByTheListenerAndRejectedByTheForm(): void
    {
        $form = $this->createForm(new ShareCreateData());

        $form->submit('not-an-array');

        self::assertFalse($form->isSynchronized());
        self::assertFalse($form->isValid());
    }

    private function createForm(ShareCreateData $data): FormInterface
    {
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $formType = FormKitTestSupport::withMerger(new ShareCreateType($translator));

        return Forms::createFormFactoryBuilder()
            ->addExtensions([new PreloadedExtension([$formType], [])])
            ->addExtension(new ValidatorExtension(Validation::createValidator()))
            ->getFormFactory()
            ->create(ShareCreateType::class, $data, [
                'share_options' => [
                    'default_expiration' => '1h',
                    'default_max_reads'  => 1,
                    'max_reads_options'  => [1, 3],
                    'expiration_options' => [['id' => '1h', 'interval' => '1 hour']],
                ],
                'max_ciphertext_bytes' => 1024,
            ]);
    }
}
