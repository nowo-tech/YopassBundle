import { Controller } from '@hotwired/stimulus';
import { showToast } from './yopass-toast';
import { setHidden, syncHidden } from './visibility';
import { decryptPayload, payloadToDisplayAsync } from './yopass-crypto';
import { loadShareKey } from './yopass-share-keys';
import { buildFullShareUrlWithKey, type ShareUrlKeyMode } from './yopass-share-url';

type PreviewResponse = {
    status: string;
    availability?: string;
    ciphertext?: string;
    mode?: string;
    payloadKind?: string;
    publicPath?: string;
    maxReads?: number;
    readsLeft?: number;
    expiresAt?: string;
    extendable?: boolean;
    accessLog?: AccessLogEntry[];
};

type AccessLogEntry = {
    accessedAt: string;
    readNumber: number;
    ipAddress: string | null;
    userAgent: string | null;
};

type ExtendResponse = {
    status: string;
    availability?: string;
    maxReads?: number;
    readsLeft?: number;
    expiresAt?: string;
    error?: string;
};

/**
 * Lets share creators preview encrypted content and extend limits without consuming a read.
 */
export default class extends Controller {
    static targets = [
        'modal',
        'output',
        'error',
        'passwordPanel',
        'password',
        'keyPanel',
        'key',
        'downloadLink',
        'publicLink',
        'availability',
        'decryptButton',
        'extendPanel',
        'extendSummary',
        'extendExpiresIn',
        'extendMaxReads',
        'extendSuccess',
        'extendButton',
        'extendPreview',
        'extendError',
        'accessLogPanel',
        'accessLogTable',
        'accessLogBody',
        'accessLogEmpty',
        'unlock',
        'outputPanel',
    ];

    static values = {
        previewPathTemplate: String,
        extendPathTemplate: { type: String, default: '' },
        csrfToken: { type: String, default: '' },
        errors: { type: Object, default: {} },
        availabilityLabels: { type: Object, default: {} },
        extendSuccessMessage: { type: String, default: 'Updated.' },
        extendLabels: { type: Object, default: {} },
        allowEmbedInUrl: { type: Boolean, default: false },
        copiedMessage: { type: String, default: 'Copied.' },
    };

    declare readonly modalTarget: HTMLDialogElement;
    declare readonly outputTarget: HTMLElement;
    declare readonly errorTarget: HTMLElement;
    declare readonly passwordPanelTarget: HTMLElement;
    declare readonly passwordTarget: HTMLInputElement;
    declare readonly keyPanelTarget: HTMLElement;
    declare readonly keyTarget: HTMLInputElement;
    declare readonly downloadLinkTarget: HTMLAnchorElement;
    declare readonly publicLinkTarget: HTMLInputElement;
    declare readonly availabilityTarget: HTMLElement;
    declare readonly decryptButtonTarget: HTMLButtonElement;
    declare readonly extendPanelTarget: HTMLElement;
    declare readonly extendSummaryTarget: HTMLElement;
    declare readonly extendExpiresInTarget: HTMLSelectElement;
    declare readonly extendMaxReadsTarget: HTMLSelectElement;
    declare readonly extendSuccessTarget: HTMLElement;
    declare readonly extendButtonTarget: HTMLButtonElement;
    declare readonly extendPreviewTarget: HTMLElement;
    declare readonly extendErrorTarget: HTMLElement;
    declare readonly hasExtendButtonTarget: boolean;
    declare readonly hasExtendPreviewTarget: boolean;
    declare readonly hasExtendErrorTarget: boolean;
    declare readonly extendLabelsValue: Partial<Record<'preview' | 'summary' | 'reads_one' | 'reads_other', string>>;
    declare readonly accessLogPanelTarget: HTMLElement;
    declare readonly accessLogTableTarget: HTMLTableElement;
    declare readonly accessLogBodyTarget: HTMLTableSectionElement;
    declare readonly accessLogEmptyTarget: HTMLElement;
    declare readonly hasAccessLogPanelTarget: boolean;
    declare readonly hasAccessLogTableTarget: boolean;
    declare readonly hasAccessLogBodyTarget: boolean;
    declare readonly hasAccessLogEmptyTarget: boolean;
    declare readonly previewPathTemplateValue: string;
    declare readonly extendPathTemplateValue: string;
    declare readonly csrfTokenValue: string;
    declare readonly errorsValue: Record<string, string>;
    declare readonly availabilityLabelsValue: Record<string, string>;
    declare readonly extendSuccessMessageValue: string;
    declare readonly allowEmbedInUrlValue: boolean;
    declare readonly hasOutputTarget: boolean;
    declare readonly unlockTarget: HTMLElement;
    declare readonly hasUnlockTarget: boolean;
    declare readonly outputPanelTarget: HTMLElement;
    declare readonly hasOutputPanelTarget: boolean;
    declare readonly copiedMessageValue: string;
    declare readonly hasErrorTarget: boolean;
    declare readonly hasPasswordPanelTarget: boolean;
    declare readonly hasKeyPanelTarget: boolean;
    declare readonly hasDownloadLinkTarget: boolean;
    declare readonly hasPublicLinkTarget: boolean;
    declare readonly hasAvailabilityTarget: boolean;
    declare readonly hasDecryptButtonTarget: boolean;
    declare readonly hasExtendPanelTarget: boolean;
    declare readonly hasExtendSummaryTarget: boolean;
    declare readonly hasExtendSuccessTarget: boolean;

    private activeShareId: string | null = null;
    private previewData: PreviewResponse | null = null;
    private focusExtendOnOpen = false;

    /**
     * The dialog may also be closed by UiKit (close button, Escape, backdrop): reset the preview state and wipe the
     * decrypted output / key inputs then, so no plaintext stays in the hidden DOM (REQ-SEC-004).
     */
    private readonly onDialogClose = (): void => {
        this.resetDisplay();
        this.resetState();
    };

    connect(): void {
        syncHidden(this.element as HTMLElement, this.identifier);
        this.modalTarget.addEventListener('close', this.onDialogClose);
    }

    disconnect(): void {
        this.modalTarget.removeEventListener('close', this.onDialogClose);
    }

    async open(event: Event): Promise<void> {
        this.focusExtendOnOpen = false;
        await this.loadShare(event);
    }

    async openExtend(event: Event): Promise<void> {
        this.focusExtendOnOpen = true;
        await this.loadShare(event);
    }

    async decryptFromForm(event: Event): Promise<void> {
        event.preventDefault();
        await this.decrypt(this.resolveKeyMaterial());
    }

    /**
     * Extend row: the button stays disabled while both selects keep "No change…"; the "Will be: …" line previews the
     * limits the server would set (expiry: max(current, now) + the option's seconds; reads: the raised maximum adds
     * its difference to the remaining reads, as SecureShare::extendMaxReads()).
     */
    syncExtend(): void {
        const expiresIn = this.extendExpiresInTarget.value.trim();
        const maxReads = this.extendMaxReadsTarget.value.trim();
        const pending = expiresIn !== '' || maxReads !== '';

        if (this.hasExtendButtonTarget) {
            this.extendButtonTarget.disabled = !pending || this.previewData === null;
        }

        if (!this.hasExtendPreviewTarget) {
            return;
        }

        if (!pending || this.previewData === null) {
            setHidden(this.extendPreviewTarget, true);
            this.extendPreviewTarget.textContent = '';

            return;
        }

        const data = this.previewData;
        let expiresAt = data.expiresAt;
        const option = this.extendExpiresInTarget.options[this.extendExpiresInTarget.selectedIndex];
        const seconds = Number.parseInt(option?.dataset.seconds ?? '', 10);

        if (expiresIn !== '' && !Number.isNaN(seconds)) {
            const current = expiresAt ? new Date(expiresAt).getTime() : Number.NaN;
            const base = Number.isNaN(current) ? Date.now() : Math.max(current, Date.now());
            expiresAt = new Date(base + seconds * 1000).toISOString();
        }

        let readsLeft = data.readsLeft ?? 0;
        let max = data.maxReads ?? 0;
        const raised = Number.parseInt(maxReads, 10);

        if (!Number.isNaN(raised) && raised > max) {
            readsLeft += raised - max;
            max = raised;
        }

        this.extendPreviewTarget.textContent = `${this.extendLabelsValue.preview ?? 'Will be'}: ${this.formatLimits(expiresAt, readsLeft, max)}`;
        setHidden(this.extendPreviewTarget, false);
    }

    async submitExtend(event: Event): Promise<void> {
        event.preventDefault();
        this.hideExtendSuccess();
        this.hideExtendError();
        this.hideError();

        const shareId = this.activeShareId;

        if (!shareId || this.extendPathTemplateValue === '') {
            return;
        }

        const body: Record<string, string | number> = {};
        const expiresIn = this.extendExpiresInTarget.value.trim();
        const maxReads = this.extendMaxReadsTarget.value.trim();

        if (expiresIn !== '') {
            body.expiresIn = expiresIn;
        }

        if (maxReads !== '') {
            body.maxReads = Number.parseInt(maxReads, 10);
        }

        try {
            const response = await fetch(this.buildExtendUrl(shareId), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfTokenValue,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify(body),
            });

            const data = await response.json() as ExtendResponse;

            if (!response.ok) {
                this.showExtendError(data.error ?? 'EXTEND_FAILED');

                return;
            }

            this.previewData = {
                status: this.previewData?.status ?? data.status,
                ...this.previewData,
                availability: data.availability,
                maxReads: data.maxReads,
                readsLeft: data.readsLeft,
                expiresAt: data.expiresAt,
            };

            // In place, no reload: header badge, "Now: …", selects reset (button disabled again), inline success.
            this.renderMetadata(this.previewData, shareId);
            this.renderExtendPanel(this.previewData);
            this.showExtendSuccess();
        } catch {
            this.showExtendError('EXTEND_FAILED');
        }
    }

    copyPublicLink(): void {
        if (this.hasPublicLinkTarget) {
            this.copy(this.publicLinkTarget.value);
        }
    }

    /** Copies the decrypted text shown in the monospace block. */
    copyOutput(): void {
        if (this.hasOutputTarget && this.outputTarget.textContent) {
            this.copy(this.outputTarget.textContent);
        }
    }

    private copy(value: string): void {
        void navigator.clipboard?.writeText(value);
        showToast(this.modalTarget, this.copiedMessageValue);
    }

    private setOutput(text: string | null): void {
        if (this.hasOutputTarget) {
            this.outputTarget.textContent = text ?? '';
            setHidden(this.outputTarget, text === null);
        }

        if (this.hasOutputPanelTarget) {
            setHidden(this.outputPanelTarget, text === null);
        }
    }

    close(): void {
        this.hideModal();
        this.resetDisplay();
        this.resetState();
    }

    private async loadShare(event: Event): Promise<void> {
        const button = event.currentTarget as HTMLButtonElement;
        const shareId = button.dataset.shareId;

        if (!shareId) {
            return;
        }

        this.activeShareId = shareId;
        this.previewData = null;
        this.resetDisplay();
        this.showModal();

        try {
            const response = await fetch(this.buildPreviewUrl(shareId), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });

            if (!response.ok) {
                throw new Error('PREVIEW_FAILED');
            }

            this.previewData = await response.json() as PreviewResponse;
            this.renderMetadata(this.previewData, shareId);
            this.renderAccessLog(this.previewData);
            this.renderExtendPanel(this.previewData);

            if (this.focusExtendOnOpen && this.hasExtendPanelTarget) {
                this.extendPanelTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }

            const storedKey = loadShareKey(shareId);
            const mode = this.previewData.mode ?? 'key';

            if (mode === 'password') {
                if (storedKey?.mode === 'password') {
                    this.passwordTarget.value = storedKey.material;
                }

                if (this.hasPasswordPanelTarget) {
                    setHidden(this.passwordPanelTarget, false);
                }

                if (this.hasKeyPanelTarget) {
                    setHidden(this.keyPanelTarget, true);
                }
            } else if (storedKey?.mode === 'key') {
                if (this.hasKeyPanelTarget) {
                    this.keyTarget.value = storedKey.material;
                    setHidden(this.keyPanelTarget, true);
                }

                if (this.hasPasswordPanelTarget) {
                    setHidden(this.passwordPanelTarget, true);
                }

                await this.decrypt(storedKey.material);
            } else {
                if (this.hasKeyPanelTarget) {
                    setHidden(this.keyPanelTarget, false);
                }

                if (this.hasPasswordPanelTarget) {
                    setHidden(this.passwordPanelTarget, true);
                }
            }
        } catch {
            this.showError('PREVIEW_FAILED');
        }
    }

    private async decrypt(keyMaterial: string): Promise<void> {
        this.hideError();

        if (!keyMaterial) {
            this.showError(this.previewData?.mode === 'password' ? 'PASSWORD_REQUIRED' : 'KEY_REQUIRED');

            return;
        }

        if (!this.previewData?.ciphertext) {
            this.showError('PREVIEW_FAILED');

            return;
        }

        try {
            const payload = await decryptPayload(this.previewData.ciphertext, keyMaterial);
            const display = await payloadToDisplayAsync(payload);

            if (display.type === 'text') {
                this.setOutput(display.text);

                if (this.hasDownloadLinkTarget) {
                    setHidden(this.downloadLinkTarget, true);
                }
            } else {
                this.setOutput(null);

                if (this.hasDownloadLinkTarget) {
                    this.downloadLinkTarget.href = URL.createObjectURL(display.blob);
                    this.downloadLinkTarget.download = display.filename;
                    this.downloadLinkTarget.textContent = display.filename;
                    setHidden(this.downloadLinkTarget, false);
                }
            }

            this.hideDecryptControls();
        } catch {
            this.showError('INVALID_KEY');
        }
    }

    private resolveKeyMaterial(): string {
        if (this.previewData?.mode === 'password') {
            return this.passwordTarget.value;
        }

        return this.keyTarget.value.trim();
    }

    private renderMetadata(data: PreviewResponse, shareId: string): void {
        if (this.hasAvailabilityTarget && data.availability) {
            this.availabilityTarget.textContent = this.availabilityLabelsValue[data.availability] ?? data.availability;
        }

        if (this.hasPublicLinkTarget && data.publicPath) {
            const baseLink = `${window.location.origin}${data.publicPath}`;
            // The key goes into the link (URL fragment, never sent to the server) only when the host allows embedding.
            const storedKey = this.allowEmbedInUrlValue ? loadShareKey(shareId) : null;
            const mode: ShareUrlKeyMode = (data.mode ?? storedKey?.mode ?? 'key') === 'password' ? 'password' : 'key';

            this.publicLinkTarget.value = storedKey?.material
                ? buildFullShareUrlWithKey(baseLink, storedKey.material, mode)
                : baseLink;
        }
    }

    private renderAccessLog(data: PreviewResponse): void {
        if (!this.hasAccessLogPanelTarget || data.accessLog === undefined) {
            if (this.hasAccessLogPanelTarget) {
                setHidden(this.accessLogPanelTarget, true);
            }

            return;
        }

        const entries = data.accessLog;

        if (entries.length === 0) {
            setHidden(this.accessLogPanelTarget, false);

            if (this.hasAccessLogEmptyTarget) {
                setHidden(this.accessLogEmptyTarget, false);
            }

            if (this.hasAccessLogTableTarget) {
                setHidden(this.accessLogTableTarget, true);
            }

            if (this.hasAccessLogBodyTarget) {
                this.accessLogBodyTarget.replaceChildren();
            }

            return;
        }

        setHidden(this.accessLogPanelTarget, false);

        if (this.hasAccessLogEmptyTarget) {
            setHidden(this.accessLogEmptyTarget, true);
        }

        if (!this.hasAccessLogBodyTarget || !this.hasAccessLogTableTarget) {
            return;
        }

        this.accessLogBodyTarget.replaceChildren();

        for (const entry of entries) {
            const row = document.createElement('tr');

            const whenCell = document.createElement('td');
            whenCell.textContent = this.formatDateTime(entry.accessedAt);
            row.appendChild(whenCell);

            const readCell = document.createElement('td');
            readCell.textContent = String(entry.readNumber);
            row.appendChild(readCell);

            const ipCell = document.createElement('td');
            ipCell.textContent = entry.ipAddress ?? '—';
            row.appendChild(ipCell);

            const uaCell = document.createElement('td');
            uaCell.textContent = entry.userAgent ?? '—';
            uaCell.style.overflowWrap = 'anywhere';
            row.appendChild(uaCell);

            this.accessLogBodyTarget.appendChild(row);
        }

        setHidden(this.accessLogTableTarget, false);
    }

    private renderExtendPanel(data: PreviewResponse): void {
        if (!this.hasExtendPanelTarget) {
            return;
        }

        if (!data.extendable) {
            setHidden(this.extendPanelTarget, true);

            return;
        }

        setHidden(this.extendPanelTarget, false);

        if (this.hasExtendSummaryTarget) {
            this.extendSummaryTarget.textContent = this.formatLimits(data.expiresAt, data.readsLeft ?? 0, data.maxReads ?? 0);
        }

        const currentMaxReads = data.maxReads ?? 0;

        for (const option of Array.from(this.extendMaxReadsTarget.options)) {
            if (option.value === '') {
                option.hidden = false;
                continue;
            }

            const value = Number.parseInt(option.value, 10);
            option.hidden = Number.isNaN(value) || value <= currentMaxReads;
        }

        this.extendExpiresInTarget.value = '';
        this.extendMaxReadsTarget.value = '';
        this.syncExtend();
    }

    /**
     * Limits line ("Now: …" / "Will be: …"): the translated `summary` (%expires%, %reads%) with `reads_one` /
     * `reads_other` (%count% = reads left) when the page passes them, else the 1.13 "<expiry> · <left> / <max>".
     */
    private formatLimits(expiresAt: string | undefined, readsLeft: number, maxReads: number): string {
        const expires = expiresAt ? this.formatDateTime(expiresAt) : '—';
        const labels = this.extendLabelsValue;

        if (!labels.summary) {
            return `${expires} · ${readsLeft} / ${maxReads}`;
        }

        const reads = (readsLeft === 1 ? labels.reads_one : labels.reads_other) ?? '%count%';

        return labels.summary
            .replace('%expires%', expires)
            .replace('%reads%', reads.replace('%count%', String(readsLeft)));
    }

    private formatDateTime(value: string): string {
        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return date.toLocaleString();
    }

    private buildPreviewUrl(shareId: string): string {
        return this.substituteShareId(this.previewPathTemplateValue, shareId);
    }

    private buildExtendUrl(shareId: string): string {
        return this.substituteShareId(this.extendPathTemplateValue, shareId);
    }

    /** Symfony URL-encodes `{id}` in generated paths as `%7Bid%7D`. */
    private substituteShareId(template: string, shareId: string): string {
        return template.replace(/%7Bid%7D|\{id\}/g, shareId);
    }

    private resetDisplay(): void {
        this.setOutput(null);

        if (this.hasDownloadLinkTarget) {
            this.clearDownloadLink();
        }

        if (this.hasPasswordPanelTarget) {
            setHidden(this.passwordPanelTarget, true);
            this.passwordTarget.value = '';
        }

        if (this.hasKeyPanelTarget) {
            setHidden(this.keyPanelTarget, true);
            this.keyTarget.value = '';
        }

        if (this.hasPublicLinkTarget) {
            this.publicLinkTarget.value = '';
        }

        if (this.hasAvailabilityTarget) {
            this.availabilityTarget.textContent = '';
        }

        if (this.hasExtendPanelTarget) {
            setHidden(this.extendPanelTarget, true);
        }

        if (this.hasAccessLogPanelTarget) {
            setHidden(this.accessLogPanelTarget, true);
        }

        if (this.hasAccessLogTableTarget) {
            setHidden(this.accessLogTableTarget, true);
        }

        if (this.hasAccessLogBodyTarget) {
            this.accessLogBodyTarget.replaceChildren();
        }

        if (this.hasAccessLogEmptyTarget) {
            setHidden(this.accessLogEmptyTarget, true);
        }

        this.showDecryptControls();
        this.hideExtendSuccess();
        this.hideExtendError();
        this.hideError();
    }

    /** Hides the download link and releases the blob URL that holds the decrypted file. */
    private clearDownloadLink(): void {
        const href = this.downloadLinkTarget.getAttribute('href');

        if (href !== null && href.startsWith('blob:')) {
            URL.revokeObjectURL(href);
        }

        this.downloadLinkTarget.removeAttribute('href');
        this.downloadLinkTarget.removeAttribute('download');
        this.downloadLinkTarget.textContent = '';
        setHidden(this.downloadLinkTarget, true);
    }

    private hideDecryptControls(): void {
        if (this.hasUnlockTarget) {
            setHidden(this.unlockTarget, true);
        }

        if (this.hasDecryptButtonTarget) {
            setHidden(this.decryptButtonTarget, true);
        }

        if (this.hasPasswordPanelTarget) {
            setHidden(this.passwordPanelTarget, true);
        }

        if (this.hasKeyPanelTarget) {
            setHidden(this.keyPanelTarget, true);
        }
    }

    private showDecryptControls(): void {
        if (this.hasUnlockTarget) {
            setHidden(this.unlockTarget, false);
        }

        if (this.hasDecryptButtonTarget) {
            setHidden(this.decryptButtonTarget, false);
        }
    }

    /** Native <dialog> (REQ-UI-001 modals); the UiKit dialog runtime may already have opened it. */
    private showModal(): void {
        if (!this.modalTarget.open) {
            this.modalTarget.showModal();
        }
    }

    private hideModal(): void {
        if (this.modalTarget.open) {
            this.modalTarget.close();
        }
    }

    private resetState(): void {
        this.activeShareId = null;
        this.previewData = null;
        this.focusExtendOnOpen = false;
    }

    private showError(code: string): void {
        if (!this.hasErrorTarget) {
            return;
        }

        this.errorTarget.textContent = this.errorsValue[code] ?? code;
        setHidden(this.errorTarget, false);
    }

    private hideError(): void {
        if (this.hasErrorTarget) {
            setHidden(this.errorTarget, true);
        }
    }

    /** Extend errors show inline next to the row (the dialog-level error region without that target). */
    private showExtendError(code: string): void {
        if (!this.hasExtendErrorTarget) {
            this.showError(code);

            return;
        }

        this.extendErrorTarget.textContent = this.errorsValue[code] ?? code;
        setHidden(this.extendErrorTarget, false);
    }

    private hideExtendError(): void {
        if (this.hasExtendErrorTarget) {
            setHidden(this.extendErrorTarget, true);
            this.extendErrorTarget.textContent = '';
        }
    }

    private showExtendSuccess(): void {
        if (!this.hasExtendSuccessTarget) {
            return;
        }

        this.extendSuccessTarget.textContent = this.extendSuccessMessageValue;
        setHidden(this.extendSuccessTarget, false);
    }

    private hideExtendSuccess(): void {
        if (this.hasExtendSuccessTarget) {
            setHidden(this.extendSuccessTarget, true);
            this.extendSuccessTarget.textContent = '';
        }
    }
}
