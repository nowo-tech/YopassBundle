import { Controller } from '@hotwired/stimulus';

/**
 * Confirms destructive form submits via `window.confirm` (REQ-UX-001: no inline onclick).
 */
export default class extends Controller {
    static values = {
        message: String,
    };

    declare readonly messageValue: string;

    confirm(event: Event): void {
        if (this.messageValue === '') {
            return;
        }

        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
