import { afterEach, describe, expect, it, vi } from 'vitest';
import { createBundleLogger } from './logger';

describe('createBundleLogger', () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it('prints the build time on scriptLoaded when provided', () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => {});

        createBundleLogger('yopass', { buildTime: '2026-01-01T00:00:00Z' }).scriptLoaded();

        expect(log).toHaveBeenCalledTimes(1);
        expect(log.mock.calls[0][0]).toContain('[yopass] script loaded, build: %c2026-01-01T00:00:00Z');
    });

    it('prints a plain script-loaded line without build time', () => {
        const log = vi.spyOn(console, 'log').mockImplementation(() => {});

        createBundleLogger('yopass').scriptLoaded();
        createBundleLogger('yopass', { buildTime: '' }).scriptLoaded();

        expect(log).toHaveBeenCalledTimes(2);
        expect(log.mock.calls[0][0]).toContain('[yopass] script loaded');
        expect(log.mock.calls[0][0]).not.toContain('build:');
        expect(log.mock.calls[1][0]).not.toContain('build:');
    });

    it('stays silent until debug is enabled', () => {
        const spies = {
            debug: vi.spyOn(console, 'debug').mockImplementation(() => {}),
            info: vi.spyOn(console, 'info').mockImplementation(() => {}),
            warn: vi.spyOn(console, 'warn').mockImplementation(() => {}),
            error: vi.spyOn(console, 'error').mockImplementation(() => {}),
        };
        const logger = createBundleLogger('yopass');

        logger.debug('a');
        logger.info('b');
        logger.warn('c');
        logger.error('d');

        for (const spy of Object.values(spies)) {
            expect(spy).not.toHaveBeenCalled();
        }

        logger.setDebug(true);
        const err = new Error('boom');
        logger.debug({ a: 1 });
        logger.info('text', null);
        logger.warn(42);
        logger.error(err);

        expect(spies.debug.mock.calls[0][0]).toContain('[yopass]');
        expect(spies.debug.mock.calls[0][2]).toBe('{"a":1}');
        expect(spies.info.mock.calls[0].slice(2)).toEqual(['text', null]);
        expect(spies.warn.mock.calls[0][2]).toBe(42);
        expect(spies.error.mock.calls[0][2]).toBe(err);

        logger.setDebug(false);
        logger.info('ignored');
        expect(spies.info).toHaveBeenCalledTimes(1);
    });
});
