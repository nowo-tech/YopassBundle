<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\YopassBundle\Dto\ShareCreateData;
use Nowo\YopassBundle\YopassBundle;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Contracts\Translation\TranslatorInterface;

use function array_column;
use function array_combine;
use function is_array;
use function max;

/**
 * Server-side metadata for a client-encrypted share (ciphertext is filled by the browser).
 *
 * The secret, file, encryption mode and custom password controls are unmapped, browser-only children (REQ-TWIG-005):
 * the Stimulus controller reads them by id / target and encrypts into {@code ciphertext}. {@see finishView()} gives
 * them their fixed element ids and an empty name, so they are never posted (only the encryption mode radios keep
 * their historical {@code yopass-encryption-mode} name), and the form stays url-encoded: the posted payload is the
 * same as before.
 *
 * @extends AbstractType<ShareCreateData>
 */
#[FormKitConfig('yopass')]
final class ShareCreateType extends AbstractType
{
    use ClientOnlyFieldsTrait;
    use FormOptionsTrait;

    /** Name the encryption mode radios have always been posted with (ignored by the server). */
    public const ENCRYPTION_MODE_NAME = 'yopass-encryption-mode';

    /** Payload kinds the browser can submit; anything else is rejected by validation. */
    public const PAYLOAD_KINDS = ['text', 'file'];

    /**
     * Creates the form type with the share, sharing and limit options.
     */
    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * Builds the create form (ciphertext, expiration, max reads, payload kind and browser-only inputs).
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array{
         *     default_expiration: string,
         *     default_max_reads: int,
         *     max_reads_options: list<int>,
         *     expiration_options: list<array{id: string, interval: string}>
         * } $shareOptions */
        $shareOptions       = $options['share_options'];
        $maxCiphertextBytes = max(1, (int) $options['max_ciphertext_bytes']);
        $expirationIds      = array_column($shareOptions['expiration_options'], 'id');
        $expirationChoices  = array_combine($expirationIds, $expirationIds);
        $maxReadsChoices    = array_combine(
            $shareOptions['max_reads_options'],
            $shareOptions['max_reads_options'],
        );

        $this->withBuilder($builder, function () use ($expirationChoices, $maxReadsChoices, $maxCiphertextBytes): void {
            $this->addTypedField('ciphertext', HiddenType::class, [
                'required'    => false,
                'empty_data'  => '',
                'label'       => false,
                'placeholder' => false,
                'help'        => false,
                'constraints' => [
                    new NotBlank(message: 'yopass.error.invalid_ciphertext'),
                    new Length(max: $maxCiphertextBytes, maxMessage: 'yopass.error.invalid_ciphertext'),
                ],
            ]);
            $this->addTypedField('payloadKind', HiddenType::class, [
                'required'    => false,
                'empty_data'  => 'text',
                'label'       => false,
                'placeholder' => false,
                'help'        => false,
                'constraints' => [
                    new Choice(choices: self::PAYLOAD_KINDS, message: 'yopass.error.create'),
                ],
            ]);
            $this->addChoiceField('expiresIn', [
                'label'        => 'yopass.expires.field',
                'row_attr'     => ['class' => 'mb-3'],
                'label_attr'   => ['class' => 'form-label'],
                'choices'      => $expirationChoices,
                'choice_label' => fn (string $value): string => $this->translator->trans(
                    'yopass.expires.' . $value,
                    [],
                    YopassBundle::TRANSLATION_DOMAIN,
                ),
            ]);
            $this->addChoiceField('maxReads', [
                'label'      => 'yopass.reads.field',
                'row_attr'   => ['class' => 'mb-3'],
                'label_attr' => ['class' => 'form-label'],
                'choices'    => $maxReadsChoices,
            ]);
        });

        $this->addClientOnlyFields($builder, $options);

        $builder->get('ciphertext')->addModelTransformer(new CallbackTransformer(
            static fn (string $value): string => $value,
            static fn (?string $value): string => $value ?? '',
        ));

        $builder->addEventListener(FormEvents::PRE_SUBMIT, static function (FormEvent $event): void {
            $data = $event->getData();

            if (!is_array($data)) {
                return;
            }

            $data['ciphertext'] ??= '';

            $event->setData($data);
        });
    }

    /**
     * Exposes the Stimulus values and limits to the form view.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->detachClientOnlyChildren($view, [
            'secret'         => 'yopass-secret',
            'file'           => 'yopass-file',
            'customPassword' => 'yopass-custom-password',
        ]);
        $this->renameExpandedChoice($view, 'encryptionMode', self::ENCRYPTION_MODE_NAME, [
            'auto'     => 'yopass-encryption-auto',
            'password' => 'yopass-encryption-password',
        ]);

        // The file is encrypted in the browser and never uploaded: keep the form url-encoded.
        $view->vars['multipart'] = false;
    }

    /**
     * Configures the data class, CSRF token id and type options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class'            => ShareCreateData::class,
            'csrf_token_id'         => 'yopass_create',
            'translation_domain'    => YopassBundle::TRANSLATION_DOMAIN,
            'share_options'         => [],
            'max_ciphertext_bytes'  => 700_000,
            'file_shares_enabled'   => false,
            'allow_custom_password' => true,
            'default_encryption'    => 'auto',
        ]);

        $resolver->setAllowedTypes('share_options', 'array');
        $resolver->setAllowedTypes('max_ciphertext_bytes', 'int');
        $resolver->setAllowedTypes('file_shares_enabled', 'bool');
        $resolver->setAllowedTypes('allow_custom_password', 'bool');
        $resolver->setAllowedValues('default_encryption', ['auto', 'password']);
    }

    /**
     * Browser-only controls read by the {@code nowo-yopass-create} Stimulus controller (never posted).
     *
     * @param FormBuilderInterface<mixed> $builder
     * @param array<string, mixed> $options
     */
    private function addClientOnlyFields(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('secret', TextareaType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'yopass.secret.field',
            'label_attr' => ['class' => 'form-label'],
            'attr'       => [
                'class'                          => 'form-control',
                'rows'                           => 5,
                'placeholder'                    => 'yopass.secret.placeholder',
                'data-nowo-yopass-create-target' => 'secret',
                'data-action'                    => 'input->nowo-yopass-create#syncSecretCounter',
            ],
        ]);

        if ($options['file_shares_enabled'] === true) {
            $builder->add('file', FileType::class, [
                'mapped'     => false,
                'required'   => false,
                'label'      => 'yopass.file.field',
                'label_attr' => ['class' => 'form-label'],
                'attr'       => [
                    'class'                          => 'form-control mb-3',
                    'data-nowo-yopass-create-target' => 'fileInput',
                ],
            ]);
        }

        $allowPassword = $options['allow_custom_password'] === true;
        $modes         = ['yopass.key.mode_auto' => 'auto'];
        if ($allowPassword) {
            $modes['yopass.key.mode_password'] = 'password';
        }
        $default = $allowPassword && $options['default_encryption'] === 'password' ? 'password' : 'auto';

        $builder->add('encryptionMode', ChoiceType::class, [
            'mapped'      => false,
            'required'    => true,
            'expanded'    => true,
            'multiple'    => false,
            'label'       => 'yopass.key.mode_field',
            'data'        => $default,
            'choices'     => $modes,
            'choice_attr' => static fn (string $value): array => [
                'class'                          => 'form-check-input',
                'data-nowo-yopass-create-target' => $value === 'password' ? 'encryptionPassword' : 'encryptionAuto',
                'data-action'                    => 'change->nowo-yopass-create#syncKeyMode',
            ],
        ]);

        if ($allowPassword) {
            $builder->add('customPassword', PasswordType::class, [
                'mapped'     => false,
                'required'   => false,
                'label'      => 'yopass.key.custom',
                'label_attr' => ['class' => 'form-label'],
                'attr'       => [
                    'class'                          => 'form-control',
                    'data-nowo-yopass-create-target' => 'customPassword',
                    'autocomplete'                   => 'new-password',
                ],
            ]);
        }
    }
}
