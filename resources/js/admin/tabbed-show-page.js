/**
 * Shared behaviour for a tabbed detail page (customer show, supplier show).
 *
 * All tab bodies are rendered server-side and revealed with `x-show`, so
 * this owns only which one is visible. The active tab is mirrored into the
 * URL's `?tab=` query so a deep link, a refresh, or the browser's back
 * button all land where the user was — without that, "record a payment"
 * bounces the user back to the first tab every time.
 *
 * @param {string[]} tabs Ordered tab keys. The first is the default, and
 *                        its URL stays clean (no `?tab=`).
 * @returns {(config?: {initialTab?: string}) => object} an Alpine factory
 */
export function makeTabbedShowPage(tabs) {
    const fallback = tabs[0];

    return function tabbedShowPage(config = {}) {
        return {
            tab: tabs.includes(config.initialTab) ? config.initialTab : fallback,

            init() {
                // Back/forward through the tab history.
                window.addEventListener('popstate', () => {
                    const next = new URL(window.location.href).searchParams.get('tab');
                    this.tab = tabs.includes(next) ? next : fallback;
                });
            },

            select(name) {
                if (!tabs.includes(name) || this.tab === name) return;
                this.tab = name;

                const url = new URL(window.location.href);
                // The default tab keeps its URL clean rather than pinning
                // `?tab=overview` onto every share of this page.
                if (name === fallback) {
                    url.searchParams.delete('tab');
                } else {
                    url.searchParams.set('tab', name);
                }
                window.history.pushState({ tab: name }, '', url);
            },
        };
    };
}
