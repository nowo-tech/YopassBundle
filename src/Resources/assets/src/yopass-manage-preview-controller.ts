import { Controller } from '@hotwired/stimulus';
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
        'accessLogPanel',
        'accessLogTable',
        'accessLogBody',
        'accessLogEmpty',
    ];

    static values = {
        previewPathTemplate: String,
        extendPathTemplate: { type: String, default: '' },
        csrfToken: { type: String, default: '' },
        errors: { type: Object, default: {} },
        availabilityLabels: { type: Object, default: {} },
        extendSuccessMessage: { type: String, default: 'Updated.' },
        allowEmbedInUrl: { type: Boolean, default: false },
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

    /** The dialog may also be closed by UiKit (close button, Escape, backdrop): reset the preview state then. */
    private readonly onDialogClose = (): void => {
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

    async submitExtend(event: Event): Promise<void> {
        event.preventDefault();
        this.hideExtendSuccess();
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
                this.showError(data.error ?? 'EXTEND_FAILED');

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

            this.renderMetadata(this.previewData, shareId);
            this.renderExtendPanel(this.previewData);
            this.showExtendSuccess();

            window.setTimeout(() => {
                window.location.reload();
            }, 800);
        } catch {
            this.showError('EXTEND_FAILED');
        }
    }

    copyPublicLink(): void {
        if (this.hasPublicLinkTarget) {
            void navigator.clipboard?.writeText(this.publicLinkTarget.value);
        }
    }

    close(): void {
        this.hideModal();
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
                if (this.hasOutputTarget) {
                    this.outputTarget.textContent = display.text;
                    setHidden(this.outputTarget, false);
                }

                if (this.hasDownloadLinkTarget) {
                    setHidden(this.downloadLinkTarget, true);
                }
            } else {
                if (this.hasOutputTarget) {
                    setHidden(this.outputTarget, true);
                }

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
            const expires = data.expiresAt ? this.formatDateTime(data.expiresAt) : '—';
            const reads = `${data.readsLeft ?? 0} / ${data.maxReads ?? 0}`;
            this.extendSummaryTarget.textContent = `${expires} · ${reads}`;
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
        if (this.hasOutputTarget) {
            this.outputTarget.textContent = '';
            setHidden(this.outputTarget, true);
        }

        if (this.hasDownloadLinkTarget) {
            setHidden(this.downloadLinkTarget, true);
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
        this.hideError();
    }

    private hideDecryptControls(): void {
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
