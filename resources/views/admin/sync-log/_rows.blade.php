{{--
    Table-view rows for the Sync log.

    Rendered inline by `admin.sync-log.index` (first page) and standalone by
    `SyncLogController@rows` (JSON `html`) for every subsequent page / filter /
    search. The row itself links to the log detail; the ⋮ menu carries the
    two write actions (retry / dismiss), gated on `sync.log.retry`.

    Expects: $logs (iterable of SyncLog, with user loaded).
--}}
@foreach ($logs as $log)
    <tr class="prod-row" data-dt-row
        data-dt-name="{{ $log->local_uuid }}"
        data-dt-id="{{ $log->id }}"
        @click="window.location.href = {{ \Illuminate\Support\Js::from(route('admin.sync-log.show', $log)) }}">
        <td>{{ optional($log->synced_at)->toDateTimeString() ?: '—' }}</td>
        <td class="mono">{{ $log->entity }}</td>
        <td class="mono fg-tertiary text-xs">{{ \Illuminate\Support\Str::limit($log->local_uuid, 12) }}</td>
        <td>
            @if ($log->isSuccess())
                <span class="prod-badge prod-badge-positive">{{ __('sync_log.status.success') }}</span>
            @elseif ($log->isConflict())
                <span class="prod-badge prod-badge-warning">{{ __('sync_log.status.conflict') }}</span>
            @else
                <span class="prod-badge prod-badge-danger">{{ __('sync_log.status.failed') }}</span>
            @endif
            {{-- Dismissed rows only surface on the All tab (the worklist tabs
                 filter them out), so the chip is how you spot one there. --}}
            @if (! $log->isSuccess() && $log->isResolved())
                <span class="prod-badge ms-1">{{ __('sync_log.status.dismissed') }}</span>
            @endif
        </td>
        <td>{{ $log->user?->name ?: '—' }}</td>
        <td class="fg-tertiary">{{ \Illuminate\Support\Str::limit($log->result_message ?: '—', 80) }}</td>
        <td>
            <div class="prod-row-actions">
            <x-admin.row-actions>
                <x-admin.row-action :href="route('admin.sync-log.show', $log)"
                                    icon="eye" :label="__('sync_log.actions.view')" />
                @if (auth()->user()?->hasPermission('sync.log.retry'))
                    @if ($log->isRetryable())
                        <x-admin.row-action icon="refresh" :label="__('sync_log.actions.retry')"
                            @click="$store.confirm.show({
                                title: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_retry.title')) }},
                                message: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_retry.message')) }},
                                intent: 'primary',
                                confirmLabel: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_retry.confirm')) }},
                                onConfirm: () => $submitForm({{ \Illuminate\Support\Js::from(route('admin.sync-log.retry', $log)) }}),
                            })" />
                        <x-admin.row-action icon="x" variant="danger" :label="__('sync_log.actions.dismiss')"
                            @click="$store.confirm.show({
                                title: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_dismiss.title')) }},
                                message: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_dismiss.message')) }},
                                intent: 'danger',
                                confirmLabel: {{ \Illuminate\Support\Js::from(__('sync_log.confirm_dismiss.confirm')) }},
                                onConfirm: () => $submitForm({{ \Illuminate\Support\Js::from(route('admin.sync-log.resolve', $log)) }}),
                            })" />
                    @elseif (! $log->isSuccess() && $log->isResolved())
                        <x-admin.row-action icon="refresh" :label="__('sync_log.actions.undismiss')"
                            @click="$submitForm({{ \Illuminate\Support\Js::from(route('admin.sync-log.resolve', $log)) }}, { undo: 1 })" />
                    @endif
                @endif
            </x-admin.row-actions>
            </div>
        </td>
    </tr>
@endforeach
