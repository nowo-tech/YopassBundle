import { describe, expect, it } from 'vitest';
import {
    countSecretCharacters,
    countSecretCharactersForSubmit,
    decryptPayload,
    encryptPayload,
    isSecretWithinCharacterLimit,
    payloadToDisplayAsync,
    readFileAsPayload,
} from './yopass-crypto';

describe('yopass-crypto', () => {
    it('encrypts and decrypts text payload with generated key', async () => {
        const encrypted = await encryptPayload({ kind: 'text', text: 'hello-yopass' }, { generateKey: true });

        expect(encrypted.ciphertext).toContain('"mode":"key"');
        expect(encrypted.decryptionKey).toBeTruthy();

        const plain = await decryptPayload(encrypted.ciphertext, encrypted.decryptionKey!);

        expect(plain).toEqual({ kind: 'text', text: 'hello-yopass' });
    });

    // Runs against the real libsodium build (no mock): the standard libsodium-wrappers build has no crypto_pwhash,
    // which broke password mode up to 1.8.0 (REQ-SEC-004 L1).
    it('encrypts and decrypts a text payload in password mode with the real library', async () => {
        const password = 'REDACTED-TEST-PASSWORD'; // ggignore: test placeholder password
        const encrypted = await encryptPayload({ kind: 'text', text: 'password-mode secret' }, {
            generateKey: false,
            password,
        });
        const envelope = JSON.parse(encrypted.ciphertext) as { v: number; mode: string; salt?: string; box: string };

        expect(envelope.v).toBe(1);
        expect(envelope.mode).toBe('password');
        expect(envelope.salt).toMatch(/^[A-Za-z0-9+/]+=*$/);
        expect(encrypted.decryptionKey).toBeUndefined();
        expect(encrypted.ciphertext).not.toContain('password-mode secret');

        await expect(decryptPayload(encrypted.ciphertext, password))
            .resolves.toEqual({ kind: 'text', text: 'password-mode secret' });
        await expect(decryptPayload(encrypted.ciphertext, 'wrong-password')).rejects.toThrow();
    });

    it('round-trips a file payload in password mode with the real library', async () => {
        const file = new File([new Uint8Array([0, 1, 2, 250, 255])], 'blob.bin', { type: 'application/octet-stream' });
        const payload = await readFileAsPayload(file, 1024);
        const encrypted = await encryptPayload(payload, { generateKey: false, password: 'REDACTED-TEST-PASSWORD' }); // ggignore: test placeholder password

        await expect(decryptPayload(encrypted.ciphertext, 'REDACTED-TEST-PASSWORD')).resolves.toEqual(payload); // ggignore: test placeholder password
    });

    it('rejects using generateKey and password together', async () => {
        await expect(
            encryptPayload({ kind: 'text', text: 'x' }, { generateKey: true, password: 'nope' }),
        ).rejects.toThrow('Use either generateKey or password, not both.');
    });

    it('reads a file into a payload and renders it for download', async () => {
        const file = new File(['hello-file'], 'note.txt', { type: 'text/plain' });
        const payload = await readFileAsPayload(file);

        expect(payload.kind).toBe('file');
        expect(payload.filename).toBe('note.txt');
        expect(payload.mime).toBe('text/plain');

        const display = await payloadToDisplayAsync(payload);

        expect(display.type).toBe('file');
        if (display.type === 'file') {
            expect(display.filename).toBe('note.txt');
            expect(display.blob.size).toBeGreaterThan(0);
        }
    });

    it('rejects files larger than 512 KiB', async () => {
        const large = new Uint8Array(512 * 1024 + 1);
        const file = new File([large], 'big.bin');

        await expect(readFileAsPayload(file)).rejects.toThrow('FILE_TOO_LARGE');
    });

    it('displays text payloads without sodium round-trip', async () => {
        const display = await payloadToDisplayAsync({ kind: 'text', text: 'visible' });

        expect(display).toEqual({ type: 'text', text: 'visible' });
    });

    it('decrypts legacy raw box ciphertext', async () => {
        const encrypted = await encryptPayload({ kind: 'text', text: 'legacy' }, { generateKey: true });
        const envelope = JSON.parse(encrypted.ciphertext) as { box: string };

        const plain = await decryptPayload(envelope.box, encrypted.decryptionKey!);

        expect(plain).toEqual({ kind: 'text', text: 'legacy' });
    });

    it('fails decryption with the wrong generated key', async () => {
        const first = await encryptPayload({ kind: 'text', text: 'locked' }, { generateKey: true });
        const second = await encryptPayload({ kind: 'text', text: 'other' }, { generateKey: true });

        await expect(decryptPayload(second.ciphertext, first.decryptionKey!)).rejects.toThrow();
    });

    it('counts secret characters including newlines', () => {
        expect(countSecretCharacters('a\nb\nc')).toBe(5);
        expect(countSecretCharactersForSubmit('  hello\nworld  ')).toBe(11);
    });

    it('validates secret character limits', () => {
        expect(isSecretWithinCharacterLimit('abc', 3)).toBe(true);
        expect(isSecretWithinCharacterLimit('abcd', 3)).toBe(false);
        expect(isSecretWithinCharacterLimit('a\nb', 3)).toBe(true);
    });

    it('encrypts multiline text payloads', async () => {
        const text = 'line one\nline two\nline three';
        const encrypted = await encryptPayload({ kind: 'text', text }, { generateKey: true });
        const plain = await decryptPayload(encrypted.ciphertext, encrypted.decryptionKey!);

        expect(plain).toEqual({ kind: 'text', text });
    });
});
