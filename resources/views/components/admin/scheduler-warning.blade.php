@props([
    // Which feature's page this is on — picks the wording.
    'context' => 'backup',   // backup | reports
])

{{--
    "Your scheduled thing isn't actually running" banner.

    Renders NOTHING when the cron job is healthy, so a correctly-configured
    install never sees it.

    This exists because the failure it reports is completely silent: with no
    cron job, the Backup page happily shows automatic backups switched on,
    a frequency, a retention window — and produces no backups, forever, with
    nothing anywhere saying why. A customer can (and did) sit on that for days.
    The banner goes on the screen where the customer notices the symptom, not
    only on the Scheduler page they'd have to already suspect.

    Deliberately silent on the `delayed` state: a host-capped cron that runs
    every 5 minutes still delivers daily backups fine, and crying wolf there
    would train people to ignore the banner that matters.
--}}
@php
    $status = (app(\App\Actions\System\ResolveSchedulerStatus::class))();
    $user   = auth()->user();
    $canSee = $user && ($user->is_super_admin || $user->hasPermission('settings.view'));
    $broken = in_array($status['state'], ['never', 'stale'], true);

    $key = $context === 'reports'
        ? ($status['state'] === 'never' ? 'reports_never' : 'reports_stale')
        : ($status['state'] === 'never' ? 'never' : 'stale');
@endphp

@if ($canSee && $broken)
    <div class="alert alert-danger mb-4" role="alert">
        <span class="alert-icon"><x-icon name="alert" class="w-5 h-5" /></span>
        <div class="alert-body">
            <p class="alert-msg">
                {{ __('settings.scheduler.banner.'.$key, ['when' => $status['human'] ?? '']) }}
                <a href="{{ route('admin.settings.scheduler.index') }}" class="sched-banner-link">
                    {{ __('settings.scheduler.banner.action') }}
                </a>
            </p>
        </div>
    </div>
@endif
