<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('settings.license.title') }}</title>
    @vite(['resources/css/admin.css'])
</head>
<body>
    <main class="page-wide p-6">
        <div class="alert alert-danger mb-5" role="alert">
            <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
            <div class="alert-body">
                <p class="alert-msg">{{ $message }}</p>
            </div>
        </div>
        @if (auth()->user()?->hasPermission('settings.view'))
            <a class="pos-btn pos-btn-primary" href="{{ route('admin.settings.license.index') }}">{{ __('settings.license.manage') }}</a>
        @endif
        <form method="POST" action="{{ route('logout') }}" class="mt-4">
            @csrf
            <button class="pos-btn pos-btn-secondary" type="submit">{{ __('settings.license.logout') }}</button>
        </form>
    </main>
</body>
</html>
