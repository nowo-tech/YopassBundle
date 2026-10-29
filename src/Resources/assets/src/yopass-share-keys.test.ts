// @vitest-environment happy-dom
import { beforeEach, describe, expect, it } from 'vitest';
import {
    PENDING_KEY_STORAGE,
    SHARE_KEY_STORAGE_PREFIX,
    consumePendingShareKey,
    loadShareKey,
    storePendingShareKey,
    storeShareKey,
} from './yopass-share-keys';

const MATERIAL = 'REDACTED-TEST-KEY';

describe('yopass-share-keys', () => {
    beforeEach(() => {
        sessionStorage.clear();
    });

    it('stores and loads material per share id', () => {
        storeShareKey('abc', 'key', MATERIAL);

        expect(JSON.parse(sessionStorage.getItem(`${SHARE_KEY_STORAGE_PREFIX}abc`)!)).toEqual({ mode: 'key', material: MATERIAL });
        expect(loadShareKey('abc')).toEqual({ mode: 'key', material: MATERIAL });
        expect(loadShareKey('other')).toBeNull();
    });

    it('ignores empty material', () => {
        storeShareKey('abc', 'key', '');
        storePendingShareKey('password', '');

        expect(sessionStorage.length).toBe(0);
    });

    it('rejects malformed stored entries', () => {
        const key = `${SHARE_KEY_STORAGE_PREFIX}abc`;

        sessionStorage.setItem(key, 'not json');
        expect(loadShareKey('abc')).toBeNull();

        sessionStorage.setItem(key, JSON.stringify({ mode: 'other', material: MATERIAL }));
        expect(loadShareKey('abc')).toBeNull();

        sessionStorage.setItem(key, JSON.stringify({ mode: 'key', material: '' }));
        expect(loadShareKey('abc')).toBeNull();

        sessionStorage.setItem(key, JSON.stringify({ mode: 'password', material: 5 }));
        expect(loadShareKey('abc')).toBeNull();
    });

    it('binds pending material to a share id and clears the pending slot', () => {
        storePendingShareKey('password', MATERIAL);

        expect(consumePendingShareKey('s1')).toEqual({ mode: 'password', material: MATERIAL });
        expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();
        expect(loadShareKey('s1')).toEqual({ mode: 'password', material: MATERIAL });
        // Second consume falls back to the bound key.
        expect(consumePendingShareKey('s1')).toEqual({ mode: 'password', material: MATERIAL });
    });

    it('falls back to the stored key when pending material is invalid', () => {
        storeShareKey('s1', 'key', MATERIAL);

        sessionStorage.setItem(PENDING_KEY_STORAGE, '{broken');
        expect(consumePendingShareKey('s1')).toEqual({ mode: 'key', material: MATERIAL });
        expect(sessionStorage.getItem(PENDING_KEY_STORAGE)).toBeNull();

        sessionStorage.setItem(PENDING_KEY_STORAGE, JSON.stringify({ mode: 'nope', material: 'x' }));
        expect(consumePendingShareKey('s1')).toEqual({ mode: 'key', material: MATERIAL });

        expect(consumePendingShareKey('missing')).toBeNull();
    });
});
