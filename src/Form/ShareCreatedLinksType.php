<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Nowo\YopassBundle\YopassBundle;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Form\FormView;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Read-only result fields of the "share created" page (REQ-TWIG-005).
 *
 * Browser-only: the {@code nowo-yopass-created} Stimulus controller fills the links and the key from the browser
 * session; the form is never submitted and the children have fixed ids and no name.
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('yopass')]
final class ShareCreatedLinksType extends AbstractType
{
    use ClientOnlyFieldsTrait;
    use FormOptionsTrait;

    /**
     * Builds the read-only link fields shown after creating a share.
     *
     * The one-click field (key embedded in the link) only exists when {@code allow_embed_in_url} is true
     * ({@code nowo_yopass.sharing.allow_embed_in_url}).
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $fields = [
            'oneClickLink' => 'resultOneClickLink',
            'shortLink'    => 'resultShortLink',
            'key'          => 'resultKey',
        ];

        if ($options['allow_embed_in_url'] !== true) {
            unset($fields['oneClickLink']);
        }

        foreach ($fields as $name => $target) {
            $builder->add($name, TextType::class, [
                'mapped'   => false,
                'required' => false,
                'label'    => false,
                'attr'     => [
                    'class'                           => 'form-control',
                    'readonly'                        => true,
                    'data-nowo-yopass-created-target' => $target,
                ],
            ]);
        }
    }

    /**
     * Exposes the created-page Stimulus values to the form view.
     */
    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->detachClientOnlyChildren($view, [
            'oneClickLink' => 'yopass-created-one-click',
            'shortLink'    => 'yopass-created-short',
            'key'          => 'yopass-created-key',
        ]);
    }

    /**
     * Configures the created-links form options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => YopassBundle::TRANSLATION_DOMAIN,
            'allow_embed_in_url' => false,
        ]);
        $resolver->setAllowedTypes('allow_embed_in_url', 'bool');
    }

    /**
     * Returns the Twig block prefix of this form type.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return 'yopass_created_links';
    }
}
