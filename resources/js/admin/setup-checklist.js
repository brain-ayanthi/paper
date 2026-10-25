/**
 * Dashboard "Get your store ready" checklist card.
 *
 * Owns three behaviours:
 *   - collapse/expand — the card starts folded to just its progress bar and
 *     opens on demand. Remembered in localStorage (a per-browser view
 *     preference, not company state).
 *   - dismiss — persisted company-wide server-side, so we hide it
 *     optimistically and let the POST settle in the background. A failed
 *     dismiss just means the card returns on the next load, which is the safe
 *     direction to fail.
 *   - reveal — the topbar account menu fires `pos:setup-card-reveal` to bring
 *     a dismissed card back without a page load.
 */
import { posPost } from '../lib/http.js';

const COLLAPSE_KEY = 'pos.dashboard.setup.collapsed';

/**
 * Collapsed unless the owner has explicitly expanded it — the card is a
 * standing reminder, not something that should push the day's numbers below
 * the fold on every visit. Absent key (and unreadable storage) reads as
 * collapsed.
 */
function readCollapsed() {
    try {
        return window.localStorage.getItem(COLLAPSE_KEY) !== '0';
    } catch (e) {
        return true;
    }
}

function writeCollapsed(collapsed) {
    try {
        window.localStorage.setItem(COLLAPSE_KEY, collapsed ? '1' : '0');
    } catch (e) {
        /* private browsing / storage disabled — the card just won't remember */
    }
}

/** Force the card open on next render. Used by the account-menu shortcut. */
export function expandSetupCard() {
    writeCollapsed(false);
}

export function setupChecklist(dismissUrl = '', restoreUrl = '') {
    return {
        visible: true,
        collapsed: readCollapsed(),

        toggle() {
            this.collapsed = !this.collapsed;
            writeCollapsed(this.collapsed);
        },

        async dismiss() {
            this.visible = false;
            if (!dismissUrl) return;
            try {
                await posPost(dismissUrl, {});
            } catch (e) {
                /* best-effort — the card simply reappears next visit */
            }
        },

        /**
         * Bring the card back after a dismiss in this same page view. The
         * server flag was already flipped, so undo it too — otherwise the
         * card would vanish again on the next load.
         */
        async reveal() {
            const wasDismissed = !this.visible;

            this.visible   = true;
            this.collapsed = false;
            writeCollapsed(false);

            this.$el.scrollIntoView({ behavior: 'smooth', block: 'start' });

            if (wasDismissed && restoreUrl) {
                try {
                    await posPost(restoreUrl, {});
                } catch (e) {
                    /* best-effort — worst case it's gone again next visit */
                }
            }
        },
    };
}
