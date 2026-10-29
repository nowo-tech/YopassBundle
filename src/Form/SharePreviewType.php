<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\YopassBundle\YopassBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

use function array_column;
use function array_combine;

/**
 * Manage page preview / extend modal controls (REQ-TWIG-005).
 *
 * Browser-only: the {@code nowo-yopass-manage-preview} Stimulus controller reads these controls by target, decrypts
 * in the browser and sends the extend request itself (JSON + {@code X-CSRF-TOKEN}). The form is never submitted;
 * the children have fixed ids and no name.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('yopass')]
final class SharePreviewType extends AbstractType
{
    use ClientOnlyFieldsTrait;
    use FormOptionsTrait;

    private const TARGET = 'data-nowo-yopass-manage-preview-target';

    /**
     * Builds the manage preview form (extend expiration / max reads).
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('password', PasswordType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'yopass.public.password_field',
            'label_attr' => ['class' => 'form-label'],
            'row_attr'   => ['class' => 'mb-3', self::TARGET => 'passwordPanel'],
            'attr'       => ['class' => 'form-control', self::TARGET => 'password', 'autocomplete' => 'off'],
        ]);
        $builder->add('key', TextType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'yopass.preview.key_field',
            'label_attr' => ['class' => 'form-label'],
            'help'       => 'yopass.preview.key_help',
            'help_attr'  => ['class' => 'text-secondary small mt-1 mb-0'],
            'row_attr'   => ['class' => 'mb-3 d-none', self::TARGET => 'keyPanel'],
            'attr'       => ['class' => 'form-control', self::TARGET => 'key', 'autocomplete' => 'off'],
        ]);
        $builder->add('publicLink', TextType::class, [
            'mapped'     => false,
            'required'   => false,
            'label'      => 'yopass.preview.public_link',
            'label_attr' => ['class' => 'form-label'],
            'attr'       => ['class' => 'form-control', 'readonly' => true, self::TARGET => 'publicLink'],
        ]);

        if ($options['extend_enabled'] !== true) {
            return;
        }

        /** @var list<array{id: string}> $expirationOptions */
        $expirationOptions = $options['share_options']['expiration_options'] ?? [];
        $expirationIds     = array_column($expirationOptions, 'id');
        /** @var list<int> $maxReadsOptions */
        $maxReadsOptions = $options['share_options']['max_reads_options'] ?? [];

        $builder->add('extendExpiresIn', ChoiceType::class, [
            'mapped'       => false,
            'required'     => false,
            'label'        => 'yopass.extend.expiration_field',
            'label_attr'   => ['class' => 'form-label'],
            'placeholder'  => 'yopass.extend.keep_expiration',
            'choices'      => $expirationIds === [] ? [] : array_combine($expirationIds, $expirationIds),
            'choice_label' => static fn (string $value): string => 'yopass.expires.' . $value,
            'attr'         => ['class' => 'form-select', self::TARGET => 'extendExpiresIn'],
        ]);
        $builder->add('extendMaxReads', ChoiceType::class, [
            'mapped'                    => false,
            'required'                  => false,
            'label'                     => 'yopass.extend.reads_field',
            'label_attr'                => ['class' => 'form-label'],
            'placeholder'               => 'yopass.extend.keep_reads',
            'choices'                   => $maxReadsOptions === [] ? [] : array_combine($maxReadsOptions, $maxReadsOptions),
            'choice_translation_domain' => false,
            'attr'                      => ['class' => 'form-select', self::TARGET => 'extendMaxReads'],
        ]);
    }

    /**
     * Exposes the preview Stimulus values to the form view.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->detachClientOnlyChildren($view, [
            'password'        => 'yopass-preview-password',
            'key'             => 'yopass-preview-key',
            'publicLink'      => 'yopass-preview-public-link',
            'extendExpiresIn' => 'yopass-extend-expires',
            'extendMaxReads'  => 'yopass-extend-reads',
        ]);
    }

    /**
     * Configures the preview form options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => YopassBundle::TRANSLATION_DOMAIN,
            'share_options'      => [],
            'extend_enabled'     => false,
        ]);
        $resolver->setAllowedTypes('share_options', 'array');
        $resolver->setAllowedTypes('extend_enabled', 'bool');
    }

    /**
     * Returns the Twig block prefix of this form type.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'yopass_preview';
    }
}
