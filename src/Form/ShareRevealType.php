<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\YopassBundle\YopassBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Public reveal page decryption input (REQ-TWIG-005).
 *
 * Browser-only: the {@code nowo-share-reveal} Stimulus controller reads the password (password mode) or the key
 * (key mode) and decrypts in the browser; the form is never submitted and the child has a fixed id and no name.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('yopass')]
final class ShareRevealType extends AbstractType
{
    use ClientOnlyFieldsTrait;
    use FormOptionsTrait;

    /**
     * Builds the public reveal form (password input in password mode).
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if ($options['decryption_mode'] === 'password') {
            $builder->add('password', PasswordType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => 'yopass.public.password_field',
                'row_attr' => ['data-nowo-share-reveal-target' => 'passwordPanel'],
                'attr'     => ['class' => 'nowo-ui-input nowo-ui-input--wide', 'data-nowo-share-reveal-target' => 'password', 'autocomplete' => 'off'],
            ]);

            return;
        }

        $builder->add('key', TextType::class, [
            'mapped'   => false,
            'required' => false,
            'label'    => 'yopass.public.key_field',
            'help'     => 'yopass.public.key_help',
            'row_attr' => ['hidden' => true, 'data-nowo-share-reveal-target' => 'keyPanel'],
            'attr'     => ['class' => 'nowo-ui-input nowo-ui-input--wide', 'data-nowo-share-reveal-target' => 'key', 'autocomplete' => 'off'],
        ]);
    }

    /**
     * Exposes the reveal Stimulus values to the form view.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->detachClientOnlyChildren($view, [
            'password' => 'share-password',
            'key'      => 'share-key',
        ]);
    }

    /**
     * Configures the decryption mode option.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => YopassBundle::TRANSLATION_DOMAIN,
            'decryption_mode'    => 'key',
        ]);
        $resolver->setAllowedTypes('decryption_mode', 'string');
    }

    /**
     * Returns the Twig block prefix of this form type.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'yopass_reveal';
    }
}
