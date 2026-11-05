/**
 * Short UiKit toast (`nowo-ui-toast*` markup, no inline style) inside a container, e.g. an open modal `<dialog>`
 * (top layer: a toast outside it would sit under the backdrop). Removed after `timeoutMs`.
 */
export function showToast(container: HTMLElement, message: string, timeoutMs = 2500): HTMLElement {
    let stack = container.querySelector<HTMLElement>(':scope > [data-nowo-ui-toast-stack]');

    if (stack === null) {
        stack = document.createElement('div');
        stack.className = 'nowo-ui-toast-stack';
        stack.setAttribute('data-nowo-ui-toast-stack', '');
        stack.setAttribute('aria-live', 'polite');
        container.appendChild(stack);
    }

    const toast = document.createElement('div');
    toast.className = 'nowo-ui-toast';
    toast.setAttribute('data-nowo-ui-toast', '');
    toast.setAttribute('role', 'status');

    const content = document.createElement('div');
    content.className = 'nowo-ui-toast__content';
    const text = document.createElement('p');
    text.className = 'nowo-ui-toast__message';
    text.textContent = message;
    content.appendChild(text);
    toast.appendChild(content);
    stack.appendChild(toast);

    window.setTimeout(() => {
        toast.remove();
        if (stack !== null && stack.childElementCount === 0) {
            stack.remove();
        }
    }, timeoutMs);

    return toast;
}
