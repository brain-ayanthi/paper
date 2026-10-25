import { posGetHtml } from '../lib/http.js';

let navigationVersion = 0;

export async function navigateInstaller(destination, { replace = false } = {}) {
    const version = ++navigationVersion;
    const url = new URL(destination, window.location.href);
    const shell = document.getElementById('installer-shell');
    const root = shell?.dataset.installRoot?.replace(/\/+$/, '');
    if (url.origin !== window.location.origin || !root
        || !(url.pathname === root || url.pathname.startsWith(`${root}/`))) {
        window.location.assign(url.href);
        return;
    }
    const html = await posGetHtml(url.href);
    if (version !== navigationVersion) return;
    const page = html ? new DOMParser().parseFromString(html, 'text/html') : null;
    const incoming = page?.getElementById('installer-shell');
    if (!incoming) {
        // Retry only the GET navigation; never repeat the successful POST.
        window.location.assign(url.href);
        return;
    }
    const csrf = page.querySelector('meta[name="csrf-token"]')?.content;
    if (csrf) document.querySelector('meta[name="csrf-token"]').content = csrf;
    document.documentElement.lang = page.documentElement.lang;
    document.documentElement.dir = page.documentElement.dir;
    document.title = page.title;
    window.Alpine.mutateDom(() => {
        window.Alpine.destroyTree(shell);
        shell.replaceWith(incoming);
        window.Alpine.initTree(incoming);
    });
    history[replace ? 'replaceState' : 'pushState']({}, '', incoming.dataset.url || url.href);
    window.scrollTo(0, 0);
    const heading = incoming.querySelector('main h2');
    heading?.setAttribute('tabindex', '-1');
    heading?.focus({ preventScroll: true });
}

export function registerInstallerNavigation() {
    document.addEventListener('click', (event) => {
        const link = event.target.closest('a[href]');
        if (!link || !link.closest('#installer-shell') || event.defaultPrevented
            || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey
            || link.target || link.hasAttribute('download')) return;
        const url = new URL(link.href);
        const root = document.getElementById('installer-shell')?.dataset.installRoot?.replace(/\/+$/, '');
        if (url.origin !== location.origin || url.hash || !root
            || !(url.pathname === root || url.pathname.startsWith(`${root}/`))) return;
        event.preventDefault();
        navigateInstaller(url.href);
    });
    window.addEventListener('popstate', () => navigateInstaller(location.href, { replace: true }));
}
