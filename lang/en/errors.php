<?php

return [
    // Shown as a toast when an authenticated user hits a page/action they
    // lack permission for — instead of the bare 403 error screen, they're
    // bounced back to the dashboard with this message.
    'unauthorized' => 'You don’t have permission to access that page.',
    // Used by the AccessibleStore validation rule (SEC #11/#25/#33/#41/#51).
    'store_not_accessible' => 'You don’t have access to the selected store.',

    // Shared field messages — used wherever the same identifier appears
    // (GSTIN / PAN are on both the customer and the supplier form).
    'gstin_size'      => 'GSTIN must be exactly 15 characters.',
    'gstin_alpha_num' => 'GSTIN can only contain letters and numbers — no spaces, dashes or symbols.',
    'pan_size'        => 'PAN must be exactly 10 characters.',
    'pan_alpha_num'   => 'PAN can only contain letters and numbers — no spaces, dashes or symbols.',
];
