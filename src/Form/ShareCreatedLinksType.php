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

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach ([
            'oneClickLink' => 'resultOneClickLink',
            'shortLink'    => 'resultShortLink',
            'key'          => 'resultKey',
        ] as $name => $target) {
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

    public function finishView(FormView $view, FormInterface $form, array $options): void
    {
        $this->detachClientOnlyChildren($view, [
            'oneClickLink' => 'yopass-created-one-click',
            'shortLink'    => 'yopass-created-short',
            'key'          => 'yopass-created-key',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'method'             => 'GET',
            'csrf_protection'    => false,
            'translation_domain' => YopassBundle::TRANSLATION_DOMAIN,
        ]);
    }

    #[Override]
    public function getBlockPrefix(): string
    {
        return 'yopass_created_links';
    }
}
