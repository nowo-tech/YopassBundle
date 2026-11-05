// @vitest-environment happy-dom
import { afterEach, describe, expect, it, vi } from 'vitest';
import { showToast } from './yopass-toast';

describe('showToast', () => {
    afterEach(() => {
        vi.useRealTimers();
        document.body.innerHTML = '';
    });

    it('reuses the stack, prints text only and removes the toast and the empty stack after the timeout', () => {
        vi.useFakeTimers();
        const container = document.createElement('dialog');
        document.body.appendChild(container);

        const first = showToast(container, '<b>Copied</b>', 1000);
        showToast(container, 'Again', 2000);

        const stacks = container.querySelectorAll('[data-nowo-ui-toast-stack]');
        expect(stacks).toHaveLength(1);
        expect(stacks[0].getAttribute('aria-live')).toBe('polite');
        expect(first.querySelector('.nowo-ui-toast__message')?.textContent).toBe('<b>Copied</b>');
        expect(first.querySelector('b')).toBeNull();

        vi.advanceTimersByTime(1000);
        expect(container.querySelectorAll('[data-nowo-ui-toast]')).toHaveLength(1);

        vi.advanceTimersByTime(1000);
        expect(container.querySelector('[data-nowo-ui-toast-stack]')).toBeNull();
    });
});
