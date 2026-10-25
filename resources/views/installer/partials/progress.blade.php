@php
    $modeAvailable = app(\App\Support\InstallerSaasEntry::class)->choiceAvailable();
    $saasSelected = $modeAvailable
        && request()->session()->get(\App\Support\InstallerSaasEntry::SESSION_MODE) === \App\Support\InstallerSaasEntry::MODE_SAAS;
    $hostingStepAvailable = $saasSelected
        && app(\App\Support\InstallerSaasEntry::class)->hostingChoiceAvailable();
    $licenseRequired = (bool) config('pos.license.required') || $saasSelected;
    $isModePage = request()->routeIs('install.mode');
    $isHostingPage = request()->routeIs('install.saas.hosting');

    $labels = [__('installer.steps.welcome')];
    if ($modeAvailable) {
        $labels[] = __('installer.steps.mode');
    }
    if ($saasSelected) {
        $labels[] = __('installer.steps.saas_admin');
    }
    if ($hostingStepAvailable) {
        $labels[] = __('installer.steps.hosting');
    }
    $labels[] = __('installer.steps.requirements');
    if ($licenseRequired) {
        $labels[] = __('installer.steps.license');
    }
    if ($saasSelected) {
        $labels[] = __('installer.steps.saas_purchase');
        $labels[] = __('installer.steps.owner');
        $labels[] = __('installer.steps.platform');
    } else {
        $labels[] = __('installer.steps.database');
        $labels[] = __('installer.steps.admin');
        $labels[] = __('installer.steps.demo');
    }

    if ($saasSelected) {
        $currentStep = match (true) {
            request()->routeIs('install.mode', 'install.saas.package') => 2,
            request()->routeIs('install.saas.admin') => 3,
            request()->routeIs('install.saas.hosting', 'install.saas.shared-hosting', 'install.saas.vps') => 4,
            request()->routeIs('install.requirements') => 5,
            request()->routeIs('install.license') => 6,
            request()->routeIs('install.saas.purchase') => 7,
            request()->routeIs('install.saas.owner') => 8,
            request()->routeIs('install.saas.progress') => 9,
            default => $currentStep,
        };
    } else {
        if ($modeAvailable && ! $isModePage && ! $isHostingPage && $currentStep > 1) {
            $currentStep += 1;
        }
        if (! $licenseRequired && $currentStep > ($modeAvailable ? 4 : 3)) {
            $currentStep -= 1;
        }
    }

    $steps = [];
    foreach ($labels as $i => $label) {
        $steps[$i + 1] = $label;
    }

    $total = count($steps);
    $currentLabel = $steps[$currentStep] ?? '';
    $progressPct = $total > 1 ? (($currentStep - 1) / ($total - 1)) * 100 : 100;
@endphp

<div class="mb-2 flex items-baseline justify-between">
    <div class="eyebrow">
        {{ __('installer.step_x_of_y', ['current' => $currentStep, 'total' => $total]) }}
        <span class="fg-tertiary mx-1.5">·</span>
        <span class="fg-secondary normal-case tracking-normal text-[12px] font-semibold">{{ $currentLabel }}</span>
    </div>
    <div class="text-[11px] mono fg-tertiary">{{ $currentStep }}/{{ $total }}</div>
</div>

<div class="relative">
    <div class="absolute top-1/2 left-0 right-0 -translate-y-1/2 h-px bg-[var(--border-subtle)]"></div>
    <div class="absolute top-1/2 left-0 -translate-y-1/2 h-px transition-all duration-300 bg-[var(--accent)]"
         style="width: {{ $progressPct }}%"></div>

    <ol class="relative flex items-center justify-between">
        @foreach ($steps as $num => $label)
            @php
                $isActive = $num == $currentStep;
                $isDone = $num < $currentStep;
            @endphp
            <li class="relative" title="{{ $label }}">
                @if ($isDone)
                    <span class="flex h-5 w-5 items-center justify-center rounded-full bg-[var(--accent)] text-[var(--accent-fg)]">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" width="10" height="10"><path d="M20 6 9 17l-5-5"/></svg>
                    </span>
                @elseif ($isActive)
                    <span class="block h-5 w-5 rounded-full installer-step-dot-active"></span>
                @else
                    <span class="block h-2.5 w-2.5 rounded-full mx-[5px] installer-step-dot-todo"></span>
                @endif
            </li>
        @endforeach
    </ol>
</div>
