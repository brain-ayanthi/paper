import { makeTabbedShowPage } from './tabbed-show-page.js';

/**
 * Alpine factory for the unified supplier view (`admin/suppliers/show`).
 * Payables mirror of `customerShowPage`, minus the Addresses tab — a
 * supplier's address is flat columns on the row, so it lives in Overview.
 * Must match the `x-show` bodies in
 * `resources/views/admin/suppliers/show.blade.php`.
 */
export const supplierShowPage = makeTabbedShowPage([
    'overview',
    'purchases',
    'returns',
    'statement',
    'payments',
]);
