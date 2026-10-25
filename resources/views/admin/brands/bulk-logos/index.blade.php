<x-admin-layout
    active="brands"
    :title="__('brands.bulk_logos.title')"
    :crumbs="[
        ['label' => __('brands.crumb_parent')],
        ['label' => __('brands.title'), 'href' => route('admin.brands.index')],
        ['label' => __('brands.bulk_logos.title')],
    ]">

    {{-- Two ways to get artwork onto many brands at once, and neither replaces
         the other — the mirror of the product bulk-images screen:

           Upload files — drop a folder; each file is matched to a brand by the
           filename declared in the import's Logo column.
           Behaviour in resources/js/admin/bulk-image-matcher.js.

           Fetch from URLs — give links and the SERVER downloads them.
           Behaviour in resources/js/admin/bulk-image-urls.js, via the shared
           <x-admin.bulk-url-panel> component. --}}
    <div class="page-wide" x-data="{ tab: '{{ $initialTab }}' }">

        <div class="page-header mb-6">
            <div class="flex items-start gap-3">
                <a href="{{ route('admin.brands.index') }}"
                   class="pos-btn pos-btn-sm pos-btn-ghost"
                   aria-label="{{ __('brands.bulk_logos.back') }}">
                    <x-icon name="back" class="w-4 h-4" />
                </a>
                <div>
                    <h1 class="page-title">{{ __('brands.bulk_logos.title') }}</h1>
                    <p class="page-sub">{{ __('brands.bulk_logos.sub') }}</p>
                </div>
            </div>
        </div>

        <div class="tabs mb-5" role="tablist">
            <button type="button" class="tab" role="tab"
                    :class="{ 'is-active': tab === 'files' }"
                    :aria-selected="tab === 'files'"
                    @click="tab = 'files'">
                <x-icon name="upload" class="w-4 h-4" />
                {{ __('brands.bulk_logos.tab_files') }}
                @if ($pendingCount > 0)
                    <span class="tab-count">{{ number_format($pendingCount) }}</span>
                @endif
            </button>
            <button type="button" class="tab" role="tab"
                    :class="{ 'is-active': tab === 'urls' }"
                    :aria-selected="tab === 'urls'"
                    @click="tab = 'urls'">
                <x-icon name="link" class="w-4 h-4" />
                {{ __('brands.bulk_logos.tab_urls') }}
                @if ($pendingUrlCount > 0)
                    <span class="tab-count">{{ number_format($pendingUrlCount) }}</span>
                @endif
            </button>
        </div>

        {{-- ─────────────────────────── Files ─────────────────────── --}}
        <div x-show="tab === 'files'"
             x-data="bulkImageMatcher({{ \Illuminate\Support\Js::from([
                'matchUrl'  => route('admin.brands.bulk-logos.match'),
                'storeUrl'  => route('admin.brands.bulk-logos.store'),
                'idKey'     => 'brand_id',
                'maxKb'     => $maxKb,
                'maxBatch'  => $maxBatch,
                'accept'    => $acceptMimes,
                'labels'    => [
                    'summary'        => __('brands.bulk_logos.summary'),
                    'matched_title'  => __('brands.bulk_logos.matched_title'),
                    'unmatched_title'=> __('brands.bulk_logos.unmatched_title'),
                    'upload_button'  => __('brands.bulk_logos.upload_button'),
                    'uploading'      => __('brands.bulk_logos.uploading'),
                    'done_summary'   => __('brands.bulk_logos.done_summary'),
                    'reason_no_match'=> __('brands.bulk_logos.reason_no_match'),
                    'reason_duplicate' => __('brands.bulk_logos.reason_duplicate'),
                    'error_generic'  => __('brands.bulk_logos.error_generic'),
                ],
             ]) }})">

            {{-- How it works --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="card-title">{{ __('brands.bulk_logos.how_title') }}</div>
                    <ol class="import-guide-list mt-2">
                        <li>{{ __('brands.bulk_logos.how_1') }}</li>
                        <li>{{ __('brands.bulk_logos.how_2') }}</li>
                        <li>{{ __('brands.bulk_logos.how_3') }}</li>
                    </ol>

                    @if ($pendingCount > 0)
                        <p class="page-sub mt-3">
                            {{ __('brands.bulk_logos.pending_hint', ['count' => number_format($pendingCount)]) }}
                        </p>
                    @else
                        <p class="page-sub mt-3">{{ __('brands.bulk_logos.no_pending') }}</p>
                    @endif
                </div>
            </div>

            {{-- Drop zone --}}
            <div class="card mb-4">
                <div class="card-body">
                    <div class="bulk-img-drop"
                         :class="{ 'is-dragover': dragOver }"
                         @dragover.prevent="dragOver = true"
                         @dragleave.prevent="dragOver = false"
                         @drop.prevent="onDrop($event)"
                         @click="$refs.picker.click()"
                         role="button"
                         tabindex="0"
                         @keydown.enter="$refs.picker.click()"
                         @keydown.space.prevent="$refs.picker.click()">
                        <input type="file"
                               x-ref="picker"
                               class="sr-only"
                               multiple
                               :accept="acceptAttr"
                               @change="onPick($event)">
                        <span class="bulk-img-drop-icon"><x-icon name="image" class="w-6 h-6" /></span>
                        <div class="bulk-img-drop-title">{{ __('brands.bulk_logos.drop_title') }}</div>
                        <div class="bulk-img-drop-hint">{{ __('brands.bulk_logos.drop_hint', ['size' => $maxKb / 1024]) }}</div>
                    </div>
                </div>
            </div>

            {{-- Matching spinner --}}
            <div class="card mb-4" x-show="phase === 'matching'" x-cloak>
                <div class="card-body flex items-center gap-3">
                    <span class="inline-flex animate-spin"><x-icon name="refresh" class="w-4 h-4" /></span>
                    <span>{{ __('brands.bulk_logos.matching') }}</span>
                </div>
            </div>

            {{-- Nothing matched --}}
            <template x-if="phase === 'ready' && matched.length === 0">
                <x-alert type="warning" class="mb-4">{{ __('brands.bulk_logos.nothing_matched') }}</x-alert>
            </template>

            {{-- Results / preview --}}
            <div class="card mb-4" x-show="phase === 'ready' || phase === 'uploading' || phase === 'done'" x-cloak>
                <div class="card-body">
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                        <div class="card-title" x-text="summaryText"></div>
                        <div class="flex items-center gap-2">
                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost"
                                    x-show="unmatched.length > 0"
                                    @click="downloadUnmatched()">
                                <x-icon name="download" class="w-4 h-4" />
                                {{ __('brands.bulk_logos.unmatched_download') }}
                            </button>
                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost"
                                    @click="reset()">
                                {{ __('brands.bulk_logos.clear') }}
                            </button>
                        </div>
                    </div>

                    {{-- Matched list --}}
                    <div x-show="matched.length > 0">
                        <div class="bulk-img-section-title" x-text="matchedTitle"></div>
                        <ul class="bulk-img-list">
                            <template x-for="row in matched" :key="row.brand_id">
                                <li class="bulk-img-row" :class="'is-' + row.status">
                                    <span class="bulk-img-thumb">
                                        <template x-if="row.image_url">
                                            <img :src="row.image_url" alt="">
                                        </template>
                                        <template x-if="!row.image_url">
                                            <x-icon name="image" class="w-4 h-4" />
                                        </template>
                                    </span>
                                    <span class="bulk-img-meta">
                                        <span class="bulk-img-name" x-text="row.name"></span>
                                        <span class="bulk-img-sub">
                                            <span x-text="row.filename"></span>
                                            <span class="bulk-img-replace"
                                                  x-show="row.has_image && row.status === 'ready'"
                                                  x-cloak>{{ __('brands.bulk_logos.has_image_note') }}</span>
                                        </span>
                                    </span>
                                    <span class="bulk-img-status">
                                        <span x-show="row.status === 'uploading'" class="inline-flex animate-spin"><x-icon name="refresh" class="w-3.5 h-3.5" /></span>
                                        <span x-show="row.status === 'done'" class="bulk-img-ok"><x-icon name="check" class="w-4 h-4" /></span>
                                        <span x-show="row.status === 'error'" class="bulk-img-err" :title="row.error"><x-icon name="x" class="w-4 h-4" /></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Unmatched list --}}
                    <div x-show="unmatched.length > 0" class="mt-4">
                        <div class="bulk-img-section-title" x-text="unmatchedTitle"></div>
                        <ul class="bulk-img-list bulk-img-list--muted">
                            <template x-for="row in unmatched" :key="row.filename">
                                <li class="bulk-img-row is-unmatched">
                                    <span class="bulk-img-thumb"><x-icon name="image" class="w-4 h-4" /></span>
                                    <span class="bulk-img-meta">
                                        <span class="bulk-img-name" x-text="row.filename"></span>
                                        <span class="bulk-img-sub" x-text="reasonLabel(row.reason)"></span>
                                    </span>
                                </li>
                            </template>
                        </ul>
                    </div>

                    {{-- Upload action / progress --}}
                    <div class="flex items-center justify-end gap-3 mt-4"
                         x-show="matched.length > 0">
                        <span class="page-sub" x-show="phase === 'uploading'" x-cloak x-text="uploadingText"></span>
                        <span class="page-sub bulk-img-done-text" x-show="phase === 'done'" x-cloak x-text="doneText"></span>
                        <button type="button"
                                class="pos-btn pos-btn-sm pos-btn-primary"
                                x-show="phase !== 'done'"
                                :disabled="phase === 'uploading'"
                                @click="upload()">
                            <x-icon name="upload" class="w-4 h-4" />
                            <span x-text="uploadButtonText"></span>
                        </button>
                        <button type="button"
                                class="pos-btn pos-btn-sm pos-btn-ghost"
                                x-show="phase === 'done'"
                                x-cloak
                                @click="reset()">
                            {{ __('brands.bulk_logos.start_over') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- ──────────────────────────── URLs ─────────────────────── --}}
        <x-admin.bulk-url-panel
            x-show="tab === 'urls'"
            x-cloak
            :match-url="route('admin.brands.bulk-logos.url-match')"
            :fetch-url="route('admin.brands.bulk-logos.url-fetch')"
            :batch-size="$urlBatchSize"
            :pending-count="$pendingUrlCount"
            ref-key="name"
            lang="brands.bulk_logos.urls" />
    </div>
</x-admin-layout>
