{{-- Topbar account-menu shortcut back to the dashboard setup checklist.
     The ring is a conic-gradient driven by --setup-pct; the click either
     reveals the card in place (already on the dashboard) or restores it
     server-side and navigates there. --}}
<button type="button"
        class="dropdown-item setup-menu-item"
        role="menuitem"
        x-data="setupProgressMenu(
            {{ Js::from(route('admin.dashboard.restore-setup')) }},
            {{ Js::from(route('admin.dashboard')) }}
        )"
        @click="go(); close()">

    <span class="setup-ring"
          style="--setup-pct: {{ $progress['percent'] }}"
          role="progressbar"
          aria-valuemin="0" aria-valuemax="100"
          aria-valuenow="{{ $progress['percent'] }}">
        <span class="setup-ring-val">{{ $progress['percent'] }}%</span>
    </span>

    <span class="setup-menu-body">
        <span class="setup-menu-title">{{ __('admin.dashboard.setup.menu_title') }}</span>
        <span class="setup-menu-sub">
            {{ __('admin.dashboard.setup.progress', ['done' => $progress['done'], 'total' => $progress['total']]) }}
        </span>
    </span>

    <x-icon name="chevron-right" class="w-3.5 h-3.5 fg-tertiary rtl:rotate-180" />
</button>
