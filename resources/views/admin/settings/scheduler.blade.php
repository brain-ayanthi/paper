<x-admin-layout
    active="settings"
    :title="__('settings.scheduler.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.scheduler.title')],
    ]">

    @php
        // state → presentation. Reuses the System Health dot/badge vocabulary so
        // "healthy" looks identical wherever the operator meets it.
        $meta = [
            'never'   => ['dot' => 'fail', 'badge' => 'prod-badge-danger',   'badge_label' => __('settings.scheduler.status.badge_fail')],
            'ok'      => ['dot' => 'ok',   'badge' => 'prod-badge-positive', 'badge_label' => __('settings.scheduler.status.badge_ok')],
            'delayed' => ['dot' => 'warn', 'badge' => 'prod-badge-warning',  'badge_label' => __('settings.scheduler.status.badge_warn')],
            'stale'   => ['dot' => 'fail', 'badge' => 'prod-badge-danger',   'badge_label' => __('settings.scheduler.status.badge_fail')],
        ][$scheduler['state']];
    @endphp

    <div class="page-wide"
         x-data="schedulerPage({{ Js::from([
             'statusUrl'  => route('admin.settings.scheduler.status'),
             'lastRunAt'  => $scheduler['last_run_at']?->toIso8601String(),
         ]) }})">

        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('settings.scheduler.title') }}</h1>
                    <p class="page-sub">{{ __('settings.scheduler.sub') }}</p>
                </div>
            </div>
        </div>

        {{-- ── Status ───────────────────────────────────────────────
             The whole point of the page. Driven entirely by the age of
             company.scheduler_last_run_at — see ResolveSchedulerStatus. --}}
        <div class="card mb-5">
            <div class="card-body">
                <div class="sched-status">
                    <span class="sysh-overall-dot is-{{ $meta['dot'] }}"></span>
                    <div class="min-w-0">
                        <div class="sched-status-title">{{ __('settings.scheduler.status.'.$scheduler['state']) }}</div>
                        <div class="sched-status-sub">{{ __('settings.scheduler.status.'.$scheduler['state'].'_sub') }}</div>
                    </div>
                    <span class="prod-badge {{ $meta['badge'] }} ms-auto shrink-0">{{ $meta['badge_label'] }}</span>
                </div>

                <div class="sched-lastrun">
                    <span class="sched-lastrun-label">{{ __('settings.scheduler.status.last_run') }}</span>
                    <span class="sched-lastrun-value mono">
                        @if ($scheduler['last_run_at'])
                            {{ format_datetime($scheduler['last_run_at']) }}
                            <span class="fg-tertiary">({{ $scheduler['human'] }})</span>
                        @else
                            {{ __('settings.scheduler.status.never_label') }}
                        @endif
                    </span>
                </div>
            </div>
        </div>

        {{-- ── Test ─────────────────────────────────────────────────
             Polls for the heartbeat to ADVANCE. Never triggers the
             scheduler itself — a pass has to come from the customer's
             own cron or it means nothing. --}}
        <div class="card mb-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.scheduler.test.title') }}</div>
                <div class="card-title-sub">{{ __('settings.scheduler.test.sub') }}</div>
            </div></div>
            <div class="card-body">
                <div class="flex items-center gap-3 flex-wrap">
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-primary"
                            x-show="!testing" @click="startTest()">
                        <x-icon name="clock" class="w-4 h-4" />
                        {{ __('settings.scheduler.test.start') }}
                    </button>
                    <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost"
                            x-show="testing" x-cloak @click="stopTest()">
                        {{ __('settings.scheduler.test.stop') }}
                    </button>
                </div>

                <div class="sched-test-result" x-show="result === 'waiting'" x-cloak>
                    <span class="sched-spinner" aria-hidden="true"></span>
                    <div>
                        <div class="sched-test-title">{{ __('settings.scheduler.test.waiting') }}</div>
                        <div class="sched-test-sub"
                             x-text="{{ Js::from(__('settings.scheduler.test.waiting_sub')) }}.replace(':seconds', remaining)"></div>
                    </div>
                </div>

                <div class="sched-test-result is-pass" x-show="result === 'passed'" x-cloak>
                    <x-icon name="check" class="w-5 h-5 shrink-0" />
                    <div>
                        <div class="sched-test-title">{{ __('settings.scheduler.test.passed') }}</div>
                        <div class="sched-test-sub">{{ __('settings.scheduler.test.passed_sub') }}</div>
                    </div>
                </div>

                <div class="sched-test-result is-fail" x-show="result === 'timedout'" x-cloak>
                    <x-icon name="alert" class="w-5 h-5 shrink-0" />
                    <div>
                        <div class="sched-test-title">{{ __('settings.scheduler.test.timedout') }}</div>
                        <div class="sched-test-sub">{{ __('settings.scheduler.test.timedout_sub') }}</div>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── The command ──────────────────────────────────────────
             Pre-filled with this install's real base path, because
             "add a cron job" is useless advice without the exact string. --}}
        <div class="card mb-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.scheduler.setup.title') }}</div>
                <div class="card-title-sub">{{ __('settings.scheduler.setup.sub') }}</div>
            </div></div>
            <div class="card-body">
                <div class="form-stack">
                    <div class="field">
                        <span class="field-label">{{ __('settings.scheduler.setup.primary_label') }}</span>
                        <div class="sched-cmd">
                            <code class="sched-cmd-text">{{ $scheduler['commands']['standard'] }}</code>
                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost shrink-0"
                                    @click="copy({{ Js::from($scheduler['commands']['standard']) }}, {{ Js::from(__('settings.scheduler.setup.copied')) }})">
                                <x-icon name="copy" class="w-4 h-4" />
                                {{ __('settings.scheduler.setup.copy') }}
                            </button>
                        </div>
                        <span class="field-help">{{ __('settings.scheduler.setup.schedule_label') }}</span>
                    </div>

                    <div class="field">
                        <span class="field-label">{{ __('settings.scheduler.setup.php_path_label') }}</span>
                        <div class="sched-cmd">
                            <code class="sched-cmd-text">{{ $scheduler['commands']['php_path'] }}</code>
                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost shrink-0"
                                    @click="copy({{ Js::from($scheduler['commands']['php_path']) }}, {{ Js::from(__('settings.scheduler.setup.copied')) }})">
                                <x-icon name="copy" class="w-4 h-4" />
                                {{ __('settings.scheduler.setup.copy') }}
                            </button>
                        </div>
                        <span class="field-help">{{ __('settings.scheduler.setup.php_path_help') }}</span>
                    </div>
                </div>
            </div>
        </div>

        {{-- ── Step-by-step per control panel ──────────────────────── --}}
        <div class="card mb-5" x-data="{ tab: 'cpanel' }">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.scheduler.guides.title') }}</div>
            </div></div>
            <div class="card-body">
                <div class="sysh-seg mb-4" role="group">
                    @foreach (['cpanel', 'plesk', 'ssh'] as $panel)
                        <button type="button" class="sysh-seg-btn"
                                :class="tab === '{{ $panel }}' ? 'is-active' : ''"
                                @click="tab = '{{ $panel }}'">{{ __('settings.scheduler.guides.'.$panel) }}</button>
                    @endforeach
                </div>

                @foreach (['cpanel', 'plesk', 'ssh'] as $panel)
                    <ol class="sched-steps" x-show="tab === '{{ $panel }}'" x-cloak>
                        @foreach (__('settings.scheduler.guides.'.$panel.'_steps') as $step)
                            <li>{!! $step !!}</li>
                        @endforeach
                    </ol>
                @endforeach
            </div>
        </div>

        {{-- ── What depends on it ───────────────────────────────────
             Named explicitly so the cron job reads as load-bearing
             rather than optional housekeeping. --}}
        <div class="card mb-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.scheduler.depends.title') }}</div>
                <div class="card-title-sub">{{ __('settings.scheduler.depends.sub') }}</div>
            </div></div>
            <div class="card-body">
                <ul class="sched-depends">
                    @foreach (__('settings.scheduler.depends.items') as $item)
                        <li>
                            <x-icon name="chevron-right" class="w-4 h-4 shrink-0 fg-tertiary" />
                            <span>{{ $item }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- ── URL cron fallback ────────────────────────────────────
             Opt-in only. For hosts that offer no shell cron at all. --}}
        <div class="card mb-5">
            <div class="card-header"><div>
                <div class="card-title">{{ __('settings.scheduler.url_cron.title') }}</div>
                <div class="card-title-sub">{{ __('settings.scheduler.url_cron.sub') }}</div>
            </div></div>
            <div class="card-body">
                <x-alert type="info" class="mb-4">{{ __('settings.scheduler.url_cron.prefer_shell') }}</x-alert>

                @if ($scheduler['url'])
                    <div class="form-stack">
                        <div class="field">
                            <span class="field-label">{{ __('settings.scheduler.url_cron.url_label') }}</span>
                            <div class="sched-cmd">
                                <code class="sched-cmd-text">{{ $scheduler['url'] }}</code>
                                <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost shrink-0"
                                        @click="copy({{ Js::from($scheduler['url']) }}, {{ Js::from(__('settings.scheduler.url_cron.url_label')) }})">
                                    <x-icon name="copy" class="w-4 h-4" />
                                    {{ __('settings.scheduler.setup.copy') }}
                                </button>
                            </div>
                        </div>

                        <div class="field">
                            <span class="field-label">{{ __('settings.scheduler.url_cron.wget_label') }}</span>
                            <div class="sched-cmd">
                                <code class="sched-cmd-text">{{ $scheduler['commands']['url_wget'] }}</code>
                                <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost shrink-0"
                                        @click="copy({{ Js::from($scheduler['commands']['url_wget']) }}, {{ Js::from(__('settings.scheduler.url_cron.wget_label')) }})">
                                    <x-icon name="copy" class="w-4 h-4" />
                                    {{ __('settings.scheduler.setup.copy') }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <x-alert type="warning" class="mt-4">{{ __('settings.scheduler.url_cron.warning') }}</x-alert>

                    {{-- The URL *is* the credential (SEC #101) — say so plainly,
                         next to the copy buttons that are about to put it in a
                         crontab and an external pinger. --}}
                    <x-alert type="warning" class="mt-3">{{ __('settings.scheduler.url_cron.secret_caution') }}</x-alert>

                    @if ($scheduler['token_expired'])
                        <x-alert type="danger" class="mt-3">{{ __('settings.scheduler.url_cron.expired') }}</x-alert>
                    @elseif ($scheduler['token_expires_at'])
                        <p class="fg-tertiary text-sm mt-3">
                            {{ __('settings.scheduler.url_cron.expires_on', ['date' => format_datetime($scheduler['token_expires_at'])]) }}
                        </p>
                    @else
                        <p class="fg-tertiary text-sm mt-3">{{ __('settings.scheduler.url_cron.expires_never') }}</p>
                    @endif

                    <div class="flex items-center gap-3 mt-4 flex-wrap">
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-secondary" @disabled(pos_is_demo())
                                @click="$store.confirm.show({
                                    title: {{ Js::from(__('settings.scheduler.url_cron.confirm_regenerate')) }},
                                    intent: 'danger',
                                    confirmLabel: {{ Js::from(__('settings.scheduler.url_cron.regenerate')) }},
                                    onConfirm: () => $submitForm({{ Js::from(route('admin.settings.scheduler.token.generate')) }}, { expires_days: expiresDays }),
                                })">
                            <x-icon name="refresh" class="w-4 h-4" />
                            {{ __('settings.scheduler.url_cron.regenerate') }}
                        </button>
                        <label class="field field-inline mb-0">
                            <span class="field-label">{{ __('settings.scheduler.url_cron.expiry_label') }}</span>
                            <select class="pos-input" x-model="expiresDays" x-init="enhancedSelect($el)">
                                <option value="365">{{ __('settings.scheduler.url_cron.expiry_365') }}</option>
                                <option value="180">{{ __('settings.scheduler.url_cron.expiry_180') }}</option>
                                <option value="90">{{ __('settings.scheduler.url_cron.expiry_90') }}</option>
                                <option value="0">{{ __('settings.scheduler.url_cron.expiry_never') }}</option>
                            </select>
                        </label>
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost pos-btn-danger" @disabled(pos_is_demo())
                                @click="$store.confirm.show({
                                    title: {{ Js::from(__('settings.scheduler.url_cron.confirm_revoke')) }},
                                    intent: 'danger',
                                    confirmLabel: {{ Js::from(__('settings.scheduler.url_cron.revoke')) }},
                                    onConfirm: () => $deleteForm({{ Js::from(route('admin.settings.scheduler.token.revoke')) }}),
                                })">
                            {{ __('settings.scheduler.url_cron.revoke') }}
                        </button>
                    </div>
                @else
                    <p class="fg-tertiary mb-4">{{ __('settings.scheduler.url_cron.none') }}</p>
                    <form method="POST" action="{{ route('admin.settings.scheduler.token.generate') }}" data-ajax-form>
                        @csrf
                        <button type="submit" class="pos-btn pos-btn-sm pos-btn-secondary" @disabled(pos_is_demo())>
                            <x-icon name="key" class="w-4 h-4" />
                            {{ __('settings.scheduler.url_cron.generate') }}
                        </button>
                    </form>
                @endif
            </div>
        </div>
    </div>
</x-admin-layout>
