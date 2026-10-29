// @vitest-environment happy-dom
import { afterEach, describe, expect, it } from 'vitest';
import {
    SHARE_DECRYPT_KEY_QUERY_PARAM,
    buildFullShareUrlWithKey,
    buildShareUrlWithKey,
    getShareKeyQueryParam,
    readShareKeyFromUrl,
    readShareKeyFromUrlAnyMode,
    stripShareKeyFromUrl,
} from './yopass-share-url';

function setUrl(pathAndQuery: string): void {
    window.history.replaceState(null, '', pathAndQuery);
}

describe('yopass-share-url', () => {
    afterEach(() => {
        setUrl('/');
    });

    it('always uses the canonical decrypt_key parameter', () => {
        expect(getShareKeyQueryParam()).toBe(SHARE_DECRYPT_KEY_QUERY_PARAM);
        expect(getShareKeyQueryParam('password')).toBe('decrypt_key');
    });

    it('reads the canonical parameter for both modes', () => {
        setUrl('/s/abc?decrypt_key=%20REDACTED-TEST-KEY%20');

        expect(readShareKeyFromUrl('key')).toBe('REDACTED-TEST-KEY');
        expect(readShareKeyFromUrl('password')).toBe('REDACTED-TEST-KEY');
        expect(readShareKeyFromUrlAnyMode()).toEqual({ material: 'REDACTED-TEST-KEY', mode: 'key' });
    });

    it('supports legacy key / password parameters', () => {
        setUrl('/s/abc?key=legacy-key');
        expect(readShareKeyFromUrl('key')).toBe('legacy-key');
        expect(readShareKeyFromUrlAnyMode()).toEqual({ material: 'legacy-key', mode: 'key' });

        setUrl('/s/abc?password=legacy-pass');
        expect(readShareKeyFromUrl('password')).toBe('legacy-pass');
        expect(readShareKeyFromUrl('key')).toBe('');
        expect(readShareKeyFromUrlAnyMode()).toEqual({ material: 'legacy-pass', mode: 'password' });
    });

    it('supports legacy hash material (URI-decoded when possible)', () => {
        setUrl('/s/abc#hash%20material');
        expect(readShareKeyFromUrl('key')).toBe('hash material');
        expect(readShareKeyFromUrl('password')).toBe('hash material');
        expect(readShareKeyFromUrlAnyMode()).toEqual({ material: 'hash material', mode: 'key' });

        setUrl('/s/abc#bad%E0%A4%A');
        expect(readShareKeyFromUrl('key')).toBe('bad%E0%A4%A');
    });

    it('returns empty / null when nothing is in the URL', () => {
        setUrl('/s/abc');

        expect(readShareKeyFromUrl('key')).toBe('');
        expect(readShareKeyFromUrl('password')).toBe('');
        expect(readShareKeyFromUrlAnyMode()).toBeNull();
    });

    it('builds relative and absolute links with the key in the query string', () => {
        expect(buildShareUrlWithKey('/s/abc?x=1', 'k/+=')).toBe('/s/abc?x=1&decrypt_key=k%2F%2B%3D');
        expect(buildFullShareUrlWithKey('https://example.test/s/abc', 'kk', 'password'))
            .toBe('https://example.test/s/abc?decrypt_key=kk');
        expect(buildFullShareUrlWithKey('/s/abc', 'kk')).toBe(`${window.location.origin}/s/abc?decrypt_key=kk`);
    });

    it('strips every key parameter but keeps the rest of the query string', () => {
        setUrl('/s/abc?decrypt_key=a&key=b&password=c&keep=1');

        stripShareKeyFromUrl();

        expect(window.location.pathname).toBe('/s/abc');
        expect(window.location.search).toBe('?keep=1');
    });
});
