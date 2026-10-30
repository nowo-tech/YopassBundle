/**
 * Show / hide helpers for the Yopass controllers (REQ-UI-003: no framework utility classes such as `d-none` in bundle
 * markup or scripts).
 *
 * Elements are hidden with the native `hidden` attribute. Theme slot classes may set `display` (e.g. a code block,
 * a button or Bootstrap's `d-block` on form errors), which wins over the user-agent `[hidden]` rule, so a hidden
 * element also gets an inline `display: none !important` (CSSOM, allowed under a strict CSP); showing it removes both.
 */
export function setHidden(element: HTMLElement, hidden: boolean): void {
    element.hidden = hidden;

    if (hidden) {
        element.style.setProperty('display', 'none', 'important');
    } else {
        element.style.removeProperty('display');
    }
}

/**
 * Whether the element is hidden through {@link setHidden} or the server-rendered `hidden` attribute.
 */
export function isHidden(element: HTMLElement): boolean {
    return element.hasAttribute('hidden');
}

/**
 * Applies {@link setHidden} to the controller's own targets rendered with the `hidden` attribute (selector
 * `[hidden][data-<identifier>-target]`), so theme classes that set `display` cannot reveal them. Called from the
 * controllers' `connect()`; other hidden elements under the root (e.g. UiKit runtime state) are left alone.
 */
export function syncHidden(root: HTMLElement, identifier: string): void {
    root.querySelectorAll<HTMLElement>(`[hidden][data-${identifier}-target]`).forEach((element) => setHidden(element, true));
}
