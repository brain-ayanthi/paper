{{-- Loyalty tab — the customer's points balance and the ledger behind
     it (docs/features/customers.md §9). Every row is append-only, so the
     table IS the audit trail: what moved, which way, what it left behind,
     and who did it.

     `customers.loyalty_points` is a running total kept on the row for
     speed; the newest ledger row's `balance_after_points` should always
     agree with it. --}}
@php
    $calc         = app(\App\Services\Loyalty\LoyaltyCalculator::class);
    $balance      = (int) ($customer->loyalty_points ?? 0);
    $balanceWorth = $calc->valueOfPoints($balance, $customer);
    $canManage    = auth()->user()?->hasPermission('customers.loyalty_manage') ?? false;
@endphp

<div x-data="{ adjustOpen: false, direction: 'add' }">
    <div class="card card-pad-0 mb-5">
        <div class="dt-toolbar">
            <div>
                <div class="dt-toolbar-title">{{ __('customers.loyalty.title') }}</div>
                <div class="card-title-sub">{{ __('customers.loyalty.sub') }}</div>
            </div>
            <div class="dt-toolbar-actions">
                @if ($canManage)
                    <button type="button" class="pos-btn pos-btn-xs pos-btn-ghost"
                            @click="adjustOpen = true">
                        <x-icon name="edit" class="w-4 h-4" />
                        {{ __('customers.loyalty.adjust') }}
                    </button>
                @endif
            </div>
        </div>

        <div class="card-body">
            <div class="cust-loyalty-summary">
                <div class="cust-loyalty-stat">
                    <div class="cust-loyalty-label">{{ __('customers.loyalty.balance') }}</div>
                    <div class="cust-loyalty-value tnum">{{ number_format($balance) }}</div>
                </div>
                <div class="cust-loyalty-stat">
                    <div class="cust-loyalty-label">{{ __('customers.loyalty.worth') }}</div>
                    <div class="cust-loyalty-value tnum">{{ format_money($balanceWorth) }}</div>
                </div>
            </div>

            @unless ($loyaltyOn)
                {{-- History stays readable after the programme is switched
                     off, but say so — otherwise a zero-movement ledger
                     looks like a bug. --}}
                <x-alert type="info" class="mt-4">{{ __('customers.loyalty.programme_off') }}</x-alert>
            @endunless
        </div>
    </div>

    <div class="card card-pad-0" x-data="dataTable({ rowsSelector: 'tbody > tr[data-dt-row]' })">
        <div class="dt-toolbar">
            <div class="dt-toolbar-title">
                {{ __('customers.loyalty.ledger_title') }}
                (<span x-text="rowCount">{{ $loyaltyRows->count() }}</span>)
            </div>
            @if ($loyaltyRows->isNotEmpty())
                <x-admin.dt-search :placeholder="__('customers.loyalty.search')" />
            @endif
        </div>

        @if ($loyaltyRows->isEmpty())
            <div class="dt-empty">
                <span class="dt-empty-icon"><x-icon name="star" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('customers.loyalty.empty_title') }}</div>
                <div class="dt-empty-sub">{{ __('customers.loyalty.empty_sub') }}</div>
            </div>
        @else
            <div class="dt-scroll">
                <table class="dt-table">
                    <thead>
                        <tr>
                            <th>{{ __('customers.loyalty.columns.date') }}</th>
                            <th>{{ __('customers.loyalty.columns.type') }}</th>
                            <th>{{ __('customers.loyalty.columns.reference') }}</th>
                            <th class="num">{{ __('customers.loyalty.columns.points') }}</th>
                            <th class="num">{{ __('customers.loyalty.columns.balance') }}</th>
                            <th>{{ __('customers.loyalty.columns.by') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($loyaltyRows as $row)
                            @php
                                $points = (int) $row->points;
                                $isEarn = $points >= 0;
                            @endphp
                            <tr class="prod-row" data-dt-row
                                data-dt-name="{{ __('customers.loyalty.types.'.$row->type).' '.$row->notes }}">
                                <td>{{ format_date($row->created_at) }}</td>
                                <td>
                                    <span class="prod-badge {{ $isEarn ? 'prod-badge-positive' : 'prod-badge-muted' }}">
                                        {{ __('customers.loyalty.types.'.$row->type) }}
                                    </span>
                                </td>
                                <td class="fg-secondary">{{ $row->notes ?: '—' }}</td>
                                <td class="num tnum font-semibold {{ $isEarn ? 'fg-success' : 'fg-danger' }}">
                                    {{ $isEarn ? '+' : '−' }}{{ number_format(abs($points)) }}
                                </td>
                                <td class="num tnum">{{ number_format((int) $row->balance_after_points) }}</td>
                                <td class="fg-secondary">{{ $row->creator?->name ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="dt-empty" x-show="dtReady && isEmpty" x-cloak>
                <span class="dt-empty-icon"><x-icon name="search" class="w-5 h-5" /></span>
                <div class="dt-empty-title">{{ __('table.empty.no_results_title') }}</div>
                <div class="dt-empty-sub">{{ __('table.empty.no_results_sub') }}</div>
            </div>

            <x-admin.dt-pager />

            @if ($loyaltyRows->count() >= $rowLimit)
                <div class="cust-tab-note">{{ __('customers.loyalty.capped', ['count' => number_format($rowLimit)]) }}</div>
            @endif
        @endif
    </div>

    {{-- Manual adjustment. Direction is a toggle rather than a signed
         number — no input in this system accepts a leading "−". --}}
    @if ($canManage)
        <div class="scrim overlay-host"
             x-show="adjustOpen" x-cloak
             @click.self="adjustOpen = false"
             @keydown.escape.window="if (adjustOpen) adjustOpen = false">
            <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="loyalty-adjust-title">
                <form method="POST" action="{{ route('admin.customers.loyalty.store', $customer) }}" data-ajax-form>
                    @csrf
                    <div class="modal-body">
                        <div class="confirm-title" id="loyalty-adjust-title">{{ __('customers.loyalty.adjust_title') }}</div>
                        <div class="confirm-msg mt-2">{{ __('customers.loyalty.adjust_sub', ['name' => $customer->name]) }}</div>

                        <div class="form-stack mt-4">
                            <div class="field">
                                <span class="field-label is-required">{{ __('customers.loyalty.fields.direction') }}</span>
                                <div class="seg" role="tablist" aria-label="{{ __('customers.loyalty.fields.direction') }}">
                                    <button type="button" role="tab"
                                            :aria-selected="direction === 'add'"
                                            :class="{ 'is-active': direction === 'add' }"
                                            @click="direction = 'add'">
                                        {{ __('customers.loyalty.direction.add') }}
                                    </button>
                                    <button type="button" role="tab"
                                            :aria-selected="direction === 'remove'"
                                            :class="{ 'is-active': direction === 'remove' }"
                                            @click="direction = 'remove'">
                                        {{ __('customers.loyalty.direction.remove') }}
                                    </button>
                                </div>
                                <input type="hidden" name="direction" :value="direction">
                            </div>

                            <label class="field">
                                <span class="field-label is-required">{{ __('customers.loyalty.fields.points') }}</span>
                                <input type="number" name="points" class="pos-input tnum"
                                       min="1" step="1" inputmode="numeric" required
                                       :max="direction === 'remove' ? {{ $balance }} : null">
                                <span class="field-help"
                                      x-show="direction === 'remove'"
                                      x-text="@js(__('customers.loyalty.fields.points_max')).replace(':max', {{ Js::from(number_format($balance)) }})"></span>
                            </label>

                            <label class="field">
                                <span class="field-label is-required">{{ __('customers.loyalty.fields.reason') }}</span>
                                <input type="text" name="reason" class="pos-input" maxlength="255" required
                                       placeholder="{{ __('customers.loyalty.fields.reason_ph') }}">
                                <span class="field-help">{{ __('customers.loyalty.fields.reason_help') }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="modal-foot">
                        <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost" @click="adjustOpen = false">
                            {{ __('customers.actions.discard') }}
                        </button>
                        <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                            {{ __('customers.loyalty.adjust_submit') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
