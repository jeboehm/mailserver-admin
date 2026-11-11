import {Controller} from '@hotwired/stimulus';

/*
 * Shows the sender choice of the API token form only while a scope that sends or drafts mail is
 * ticked, and the address only for a fixed sender. The server ignores both otherwise, so this is
 * only about keeping the form simple.
 */
/* stimulusFetch: 'lazy' */
export default class extends Controller {
    static targets = ['scope', 'sender', 'address'];

    connect() {
        this.toggle();
    }

    toggle() {
        const sendsMail = this.scopeTargets.some((box) => box.dataset.sendsMail === 'true' && box.checked);
        const mode = this.hasSenderTarget ? this.senderTarget.querySelector('input:checked')?.value : undefined;

        if (this.hasSenderTarget) {
            this.senderTarget.hidden = !sendsMail;
        }

        if (this.hasAddressTarget) {
            this.addressTarget.hidden = !sendsMail || mode !== 'fixed';
        }
    }
}
