/**
 * "Finish store setup" row in the topbar account menu.
 *
 * The only way back to a dismissed checklist, so it has to work from any
 * admin page:
 *   - already on the dashboard with the card rendered → reveal it in place
 *     (the card itself un-dismisses server-side, see setup-checklist.js);
 *   - anywhere else (or the card was dismissed on a previous visit, so the
 *     server never rendered it) → restore the flag, then land on the
 *     dashboard where the card is now waiting.
 */
import { posPost } from '../lib/http.js';
import { expandSetupCard } from './setup-checklist.js';

export function setupProgressMenu(restoreUrl = '', dashboardUrl = '') {
    return {
        // NB: not `open()` — the enclosing `dropdown` component keeps its
        // panel state in a property of that name, and a same-named member
        // here would shadow it, so `close()` would write to this scope
        // instead and the menu would never shut.
        goBusy: false,

        async go() {
            if (this.goBusy) return;
            this.goBusy = true;

            // Whichever branch we take, the card should land open — the click
            // means "show me what's left", not "show me a collapsed bar".
            expandSetupCard();

            if (document.querySelector('[data-setup-card]')) {
                window.dispatchEvent(new CustomEvent('pos:setup-card-reveal'));
                this.goBusy = false;
                return;
            }

            if (restoreUrl) {
                try {
                    await posPost(restoreUrl, {});
                } catch (e) {
                    /* best-effort — worst case the dashboard shows no card */
                }
            }

            window.location.assign(dashboardUrl);
        },
    };
}
