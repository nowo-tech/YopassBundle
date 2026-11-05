<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Event;

use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Dispatched before the manage list is loaded ({@see YopassEvents::SHARE_LIST_FILTERS}): hosts add filter fields
 * (e.g. "Shared with") to the list's GET filter form. When a listener added at least one field, the form is rendered
 * in the UiKit filters region (Symfony form rendering, escaped), submitted with the list request, and its data reaches
 * {@see ShareListQueryEvent::getFilters()} so the host's query listener can apply it.
 *
 * The form is a read-only GET search form (no CSRF token, like any list filter); it never changes data.
 */
final class ShareListFiltersEvent extends Event
{
    /**
     * Creates the event with the viewer and the filter form builder (form name `filters`, method GET).
     *
     * @param FormBuilderInterface<array<string, mixed>|null> $builder
     */
    public function __construct(
        private readonly UserInterface $viewer,
        private readonly FormBuilderInterface $builder,
    ) {
    }

    /**
     * Returns the user viewing the list.
     */
    public function getViewer(): UserInterface
    {
        return $this->viewer;
    }

    /**
     * Returns the filter form builder: add fields with {@see FormBuilderInterface::add()}.
     *
     * @return FormBuilderInterface<array<string, mixed>|null>
     */
    public function getFormBuilder(): FormBuilderInterface
    {
        return $this->builder;
    }
}
