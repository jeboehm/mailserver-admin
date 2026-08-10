import {Controller} from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['source', 'button'];

    static values = {
        successLabel: {type: String, default: 'Copied!'},
        resetDelay: {type: Number, default: 2000}
    };

    async copy() {
        const text = this.sourceTarget.textContent.trim();

        if (await this.write(text)) {
            this.showSuccess();
        }
    }

    async write(text) {
        // navigator.clipboard is unavailable on insecure origins, which is a common setup for
        // an admin UI reachable over plain HTTP inside a LAN. Fall back to the legacy API there.
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);

                return true;
            } catch {
                // Ignore and try the fallback below.
            }
        }

        return this.writeLegacy(text);
    }

    writeLegacy(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();

        try {
            return document.execCommand('copy');
        } catch {
            return false;
        } finally {
            document.body.removeChild(textarea);
        }
    }

    showSuccess() {
        if (!this.hasButtonTarget) {
            return;
        }

        const button = this.buttonTarget;

        if (this.resetTimeout) {
            clearTimeout(this.resetTimeout);
        } else {
            this.originalLabel = button.textContent;
        }

        button.textContent = this.successLabelValue;
        this.resetTimeout = setTimeout(() => {
            button.textContent = this.originalLabel;
            this.resetTimeout = null;
        }, this.resetDelayValue);
    }

    disconnect() {
        if (this.resetTimeout) {
            clearTimeout(this.resetTimeout);
            this.resetTimeout = null;
        }
    }
}
