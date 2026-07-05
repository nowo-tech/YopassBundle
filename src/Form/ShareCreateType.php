<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_combine;
use function is_array;

/**
 * Server-side metadata for a client-encrypted share (ciphertext is filled by the browser).
 */
final class ShareCreateType extends AbstractType
{
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{
         *     default_expiration: string,
         *     default_max_reads: int,
         *     max_reads_options: list<int>,
         *     expiration_options: list<array{id: string, interval: string}>
         * } $shareOptions */
        $shareOptions       = $options['share_options'];
        $maxCiphertextBytes = (int) $options['max_ciphertext_bytes'];
        $expirationIds      = array_column($shareOptions['expiration_options'], 'id');
        $expirationChoices  = array_combine($expirationIds, $expirationIds);
        $maxReadsChoices    = array_combine(
            $shareOptions['max_reads_options'],
            $shareOptions['max_reads_options'],
        );

        $builder
            ->add('ciphertext', HiddenType::class, [
                'required'    => false,
                'empty_data'  => '',
                'constraints' => [
                    new NotBlank(message: 'yopass.error.invalid_ciphertext'),
                    new Length(max: $maxCiphertextBytes, maxMessage: 'yopass.error.invalid_ciphertext'),
                ],
            ])
            ->add('payloadKind', HiddenType::class, [
                'required'   => false,
                'empty_data' => 'text',
            ])
            ->add('expiresIn', ChoiceType::class, [
                'label'        => 'yopass.expires.field',
                'choices'      => $expirationChoices,
                'choice_label' => fn (string $value): string => $this->translator->trans(
                    'yopass.expires.' . $value,
                    [],
                    YopassBundle::TRANSLATION_DOMAIN,
                ),
                'attr' => ['class' => 'form-select'],
            ])
            ->add('maxReads', ChoiceType::class, [
                'label'   => 'yopass.reads.field',
                'choices' => $maxReadsChoices,
                'attr'    => ['class' => 'form-select'],
            ]);

        $builder->get('ciphertext')->addModelTransformer(new CallbackTransformer(
            static fn (string $value): string => $value,
            static fn (?string $value): string => $value ?? '',
        ));

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            if (!isset($data['ciphertext']) || $data['ciphertext'] === null) {
                $data['ciphertext'] = '';
            }

            $event->setData($data);
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'           => ShareCreateData::class,
            'csrf_token_id'        => 'yopass_create',
            'translation_domain'   => YopassBundle::TRANSLATION_DOMAIN,
            'share_options'        => [],
            'max_ciphertext_bytes' => 700_000,
            'file_shares_enabled'  => false,
        ]);

        $resolver->setAllowedTypes('share_options', 'array');
        $resolver->setAllowedTypes('max_ciphertext_bytes', 'int');
        $resolver->setAllowedTypes('file_shares_enabled', 'bool');
    }
}
