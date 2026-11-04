// @vitest-environment happy-dom
import { describe, expect, it, vi } from 'vitest';
import { reloadPage } from './yopass-navigation';

describe('reloadPage', () => {
    it('reloads the current location', () => {
        const reload = vi.fn();
        const original = window.location;
        Object.defineProperty(window, 'location', { configurable: true, value: { ...original, reload } });

        try {
            reloadPage();
        } finally {
            Object.defineProperty(window, 'location', { configurable: true, value: original });
        }

        expect(reload).toHaveBeenCalledOnce();
    });
});
