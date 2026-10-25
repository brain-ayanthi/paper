import { makeTabbedShowPage } from './tabbed-show-page.js';

/**
 * Alpine factory for the unified customer view (`admin/customers/show`).
 * Tab reveal + `?tab=` URL sync live in {@see makeTabbedShowPage}; this
 * file owns only the tab set, which must match the `x-show` bodies in
 * `resources/views/admin/customers/show.blade.php`.
 */
export const customerShowPage = makeTabbedShowPage([
    'overview',
    'purchases',
    'quotations',
    'returns',
    'statement',
    'payments',
    // Rendered only when the loyalty programme is on or the customer has
    // points history; listing it here regardless is harmless — an unknown
    // `?tab=loyalty` just falls back to the default.
    'loyalty',
    'addresses',
]);
