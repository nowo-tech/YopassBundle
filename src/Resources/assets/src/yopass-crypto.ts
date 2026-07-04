import _sodium from 'libsodium-wrappers';

export type YopassTextPayload = {
    kind: 'text';
    text: string;
};

export type YopassFilePayload = {
    kind: 'file';
    filename: string;
    mime: string;
    data: string;
};

export type YopassPayload = YopassTextPayload | YopassFilePayload;

export type CiphertextEnvelope = {
    v: 1;
    mode: 'key' | 'password';
    salt?: string;
    box: string;
};

export type EncryptOptions = {
    generateKey: boolean;
    password?: string;
};

export type EncryptResult = {
    ciphertext: string;
    decryptionKey?: string;
};

export const DEFAULT_MAX_SECRET_CHARS = 512 * 1024;

export function countSecretCharacters(text: string): number {
    return [...text].length;
}

export function isSecretWithinCharacterLimit(text: string, maxChars: number): boolean {
    return countSecretCharacters(text) <= maxChars;
}

export function countSecretCharactersForSubmit(text: string): number {
    return countSecretCharacters(text.trim());
}

let sodiumReady: Promise<void> | null = null;

async function ready(): Promise<typeof _sodium> {
    if (sodiumReady === null) {
        sodiumReady = _sodium.ready;
    }

    await sodiumReady;

    return _sodium;
}

export async function encryptPayload(
    payload: YopassPayload,
    options: EncryptOptions,
): Promise<EncryptResult> {
    const sodium = await ready();
    const plaintext = JSON.stringify(payload);

    if (options.generateKey && options.password) {
        throw new Error('Use either generateKey or password, not both.');
    }

    if (options.password) {
        const salt = sodium.randombytes_buf(sodium.crypto_pwhash_SALTBYTES);
        const derivedKey = sodium.crypto_pwhash(
            sodium.crypto_secretbox_KEYBYTES,
            options.password,
            salt,
            sodium.crypto_pwhash_OPSLIMIT_INTERACTIVE,
            sodium.crypto_pwhash_MEMLIMIT_INTERACTIVE,
            sodium.crypto_pwhash_ALG_DEFAULT,
        );
        const box = sealBox(plaintext, derivedKey, sodium);

        return {
            ciphertext: JSON.stringify({
                v: 1,
                mode: 'password',
                salt: sodium.to_base64(salt, sodium.base64_variants.ORIGINAL),
                box,
            } satisfies CiphertextEnvelope),
        };
    }

    const secretKey = sodium.randombytes_buf(sodium.crypto_secretbox_KEYBYTES);
    const box = sealBox(plaintext, secretKey, sodium);

    return {
        ciphertext: JSON.stringify({
            v: 1,
            mode: 'key',
            box,
        } satisfies CiphertextEnvelope),
        decryptionKey: sodium.to_base64(secretKey, sodium.base64_variants.URLSAFE_NO_PADDING),
    };
}

export async function decryptPayload(ciphertext: string, keyOrPassword: string): Promise<YopassPayload> {
    const sodium = await ready();
    const envelope = parseEnvelope(ciphertext);
    const secretKey = envelope.mode === 'password'
        ? deriveKeyFromPassword(keyOrPassword, envelope.salt ?? '', sodium)
        : sodium.from_base64(keyOrPassword, sodium.base64_variants.URLSAFE_NO_PADDING);

    const plaintext = openBox(envelope.box, secretKey, sodium);

    return JSON.parse(plaintext) as YopassPayload;
}

export async function readFileAsPayload(file: File, maxFileBytes: number = 512 * 1024): Promise<YopassFilePayload> {
    if (file.size > maxFileBytes) {
        throw new Error('FILE_TOO_LARGE');
    }

    const buffer = await file.arrayBuffer();
    const bytes = new Uint8Array(buffer);
    const sodium = await ready();
    const data = sodium.to_base64(bytes, sodium.base64_variants.ORIGINAL);

    return {
        kind: 'file',
        filename: file.name,
        mime: file.type || 'application/octet-stream',
        data,
    };
}

export async function payloadToDisplayAsync(
    payload: YopassPayload,
): Promise<{ type: 'text'; text: string } | { type: 'file'; filename: string; mime: string; blob: Blob }> {
    if (payload.kind === 'text') {
        return { type: 'text', text: payload.text };
    }

    const sodium = await ready();
    const bytes = sodium.from_base64(payload.data, sodium.base64_variants.ORIGINAL);

    return {
        type: 'file',
        filename: payload.filename,
        mime: payload.mime,
        blob: new Blob([Uint8Array.from(bytes)], { type: payload.mime }),
    };
}

function sealBox(plaintext: string, secretKey: Uint8Array, sodium: typeof _sodium): string {
    const nonce = sodium.randombytes_buf(sodium.crypto_secretbox_NONCEBYTES);
    const cipher = sodium.crypto_secretbox_easy(plaintext, nonce, secretKey);
    const combined = new Uint8Array(nonce.length + cipher.length);
    combined.set(nonce);
    combined.set(cipher, nonce.length);

    return sodium.to_base64(combined, sodium.base64_variants.ORIGINAL);
}

function openBox(box: string, secretKey: Uint8Array, sodium: typeof _sodium): string {
    const decoded = sodium.from_base64(box, sodium.base64_variants.ORIGINAL);
    const nonce = decoded.slice(0, sodium.crypto_secretbox_NONCEBYTES);
    const cipher = decoded.slice(sodium.crypto_secretbox_NONCEBYTES);
    const plain = sodium.crypto_secretbox_open_easy(cipher, nonce, secretKey);

    if (!plain) {
        throw new Error('INVALID_KEY');
    }

    return sodium.to_string(plain);
}

function deriveKeyFromPassword(password: string, saltB64: string, sodium: typeof _sodium): Uint8Array {
    const salt = sodium.from_base64(saltB64, sodium.base64_variants.ORIGINAL);

    return sodium.crypto_pwhash(
        sodium.crypto_secretbox_KEYBYTES,
        password,
        salt,
        sodium.crypto_pwhash_OPSLIMIT_INTERACTIVE,
        sodium.crypto_pwhash_MEMLIMIT_INTERACTIVE,
        sodium.crypto_pwhash_ALG_DEFAULT,
    );
}

function parseEnvelope(ciphertext: string): CiphertextEnvelope {
    try {
        const parsed = JSON.parse(ciphertext) as CiphertextEnvelope;

        if (parsed.v === 1 && parsed.box) {
            return parsed;
        }
    } catch {
        // Legacy raw box without envelope.
    }

    return { v: 1, mode: 'key', box: ciphertext };
}
