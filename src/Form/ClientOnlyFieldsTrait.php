<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Form;

use Symfony\Component\Form\FormView;

/**
 * Helpers for browser-only controls (REQ-TWIG-005).
 *
 * Secrets, decryption keys and passwords are read by the Stimulus controllers and encrypted / decrypted in the
 * browser; they must never be posted. These helpers give such children the element id the scripts and labels use
 * and an empty {@code name} attribute, so browsers never include them in a form submission.
 */
trait ClientOnlyFieldsTrait
{
    /**
     * @param FormView $view Root view
     * @param array<string, string> $ids Child name => element id
     */
    private function detachClientOnlyChildren(FormView $view, array $ids): void
    {
        foreach ($ids as $child => $id) {
            if (!isset($view->children[$child])) {
                continue;
            }

            $view->children[$child]->vars['id']        = $id;
            $view->children[$child]->vars['full_name'] = '';
        }
    }

    /**
     * Expanded single choice whose radios keep a fixed shared name (grouping) and fixed ids.
     *
     * @param array<string, string> $ids Choice value => element id
     */
    private function renameExpandedChoice(FormView $view, string $child, string $fullName, array $ids): void
    {
        if (!isset($view->children[$child])) {
            return;
        }

        $choice                    = $view->children[$child];
        $choice->vars['full_name'] = $fullName;

        foreach ($choice->children as $radio) {
            $radio->vars['full_name'] = $fullName;
            $value                    = (string) ($radio->vars['value'] ?? '');
            if (isset($ids[$value])) {
                $radio->vars['id'] = $ids[$value];
            }
        }
    }
}
