<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Nowo\FormKitBundle\Attribute\FormKitConfig;
use Nowo\FormKitBundle\Form\FormOptionsTrait;
use Override;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * CSRF-only form for the revoke / delete / delete-all row actions (REQ-TWIG-005).
 *
 * The empty block prefix keeps the posted field flat ({@code _token}) with the same token ids as before
 * ({@code revoke-share-<id>}, {@code delete-share-<id>}, {@code yopass_delete_all}).
 *
 * @extends AbstractType<mixed>
 */
#[FormKitConfig('yopass')]
final class CsrfActionType extends AbstractType
{
    use FormOptionsTrait;

    /**
     * Builds a CSRF-only action form (no fields).
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // CSRF only — no application fields.
    }

    /**
     * Configures the CSRF token id and form action options.
     */
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'csrf_protection'    => true,
            'csrf_field_name'    => '_token',
            'allow_extra_fields' => true,
        ]);
        $resolver->setRequired('csrf_token_id');
        $resolver->setAllowedTypes('csrf_token_id', 'string');
    }

    /**
     * Returns an empty block prefix so the token is posted as a flat "_token" field.
     */
    #[Override]
    public function getBlockPrefix(): string
    {
        return '';
    }
}
