@php
    /*
     * Owner-supplied tracking code. The settings recovery page is always
     * excluded so a broken/redirecting snippet can be disabled safely.
     * Demo mode is excluded even if an imported database contains snippets.
     */
    $customScriptPosition = ($position ?? 'head') === 'footer' ? 'footer' : 'head';
    $customScriptSurface = ($surface ?? 'public') === 'authenticated' ? 'authenticated' : 'public';
    $customScriptRecovery = request()->routeIs('admin.settings.custom-scripts.*');
    $customScriptSettings = ['enabled' => false, 'include_authenticated' => false, 'header' => '', 'footer' => ''];

    if (! pos_is_demo() && ! $customScriptRecovery) {
        try {
            $customScriptSettings = \App\Models\Company::current()?->customScripts() ?? $customScriptSettings;
        } catch (\Throwable) {
            // Pre-migration/corrupt configuration must never break a page.
        }
    }

    $customScriptSurfaceAllowed = $customScriptSurface === 'public'
        || (bool) ($customScriptSettings['include_authenticated'] ?? false);
    $customScriptSnippet = $customScriptPosition === 'footer'
        ? (string) ($customScriptSettings['footer'] ?? '')
        : (string) ($customScriptSettings['header'] ?? '');
    $customScriptRender = (bool) ($customScriptSettings['enabled'] ?? false)
        && $customScriptSurfaceAllowed
        && trim($customScriptSnippet) !== '';
@endphp
@if ($customScriptRender)
    <!-- Custom {{ $customScriptPosition }} scripts configured by the super administrator. -->
    {!! $customScriptSnippet !!}
@endif
