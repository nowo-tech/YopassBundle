// @vitest-environment happy-dom
import { Application } from '@hotwired/stimulus';
import { afterEach, describe, expect, it, vi } from 'vitest';

describe('yopass entry', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('logs the build and registers every controller under its public identifier', async () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => {});
        const register = vi.fn();
        vi.spyOn(Application, 'start').mockReturnValue({ register } as unknown as Application);

        await import('./yopass');

        expect(log).toHaveBeenCalledTimes(1);
        expect(log.mock.calls[0][0]).toContain('[yopass] script loaded, build: %c2026-04-14T12:42:34.906Z');
        expect(register.mock.calls.map((call) => call[0])).toEqual([
            'nowo-yopass-create',
            'nowo-yopass-created',
            'nowo-yopass-manage-preview',
            'nowo-share-reveal',
        ]);

        for (const call of register.mock.calls) {
            expect(typeof call[1]).toBe('function');
        }
    });
});
