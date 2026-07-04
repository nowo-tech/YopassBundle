export const SHARE_KEY_STORAGE_PREFIX = 'nowo_yopass:key:';
export const PENDING_KEY_STORAGE = 'nowo_yopass:pending-key';

export type StoredShareKey = {
    mode: 'key' | 'password';
    material: string;
};

export function storeShareKey(shareId: string, mode: 'key' | 'password', material: string): void {
    if (material === '') {
        return;
    }

    const payload: StoredShareKey = { mode, material };
    sessionStorage.setItem(`${SHARE_KEY_STORAGE_PREFIX}${shareId}`, JSON.stringify(payload));
}

export function storePendingShareKey(mode: 'key' | 'password', material: string): void {
    if (material === '') {
        return;
    }

    const payload: StoredShareKey = { mode, material };
    sessionStorage.setItem(PENDING_KEY_STORAGE, JSON.stringify(payload));
}

export function consumePendingShareKey(shareId: string): StoredShareKey | null {
    const pendingRaw = sessionStorage.getItem(PENDING_KEY_STORAGE);

    if (pendingRaw !== null) {
        sessionStorage.removeItem(PENDING_KEY_STORAGE);

        try {
            const parsed = JSON.parse(pendingRaw) as StoredShareKey;

            if (
                (parsed.mode === 'key' || parsed.mode === 'password')
                && typeof parsed.material === 'string'
                && parsed.material !== ''
            ) {
                storeShareKey(shareId, parsed.mode, parsed.material);

                return parsed;
            }
        } catch {
            // Fall through to stored key lookup.
        }
    }

    return loadShareKey(shareId);
}

export function loadShareKey(shareId: string): StoredShareKey | null {
    const raw = sessionStorage.getItem(`${SHARE_KEY_STORAGE_PREFIX}${shareId}`);

    if (raw === null) {
        return null;
    }

    try {
        const parsed = JSON.parse(raw) as StoredShareKey;

        if (parsed.mode !== 'key' && parsed.mode !== 'password') {
            return null;
        }

        if (typeof parsed.material !== 'string' || parsed.material === '') {
            return null;
        }

        return parsed;
    } catch {
        return null;
    }
}
