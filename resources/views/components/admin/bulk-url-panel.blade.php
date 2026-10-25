@props([
    'matchUrl',              // POST endpoint for the read-only preview
    'fetchUrl',              // POST endpoint that downloads a batch
    'batchSize'    => 5,     // must match the endpoint's own cap
    'pendingCount' => 0,     // records carrying a link nobody has fetched yet
    'refKey'       => 'sku', // 'sku' for products, 'name' for brands
    'lang',                  // lang prefix, e.g. 'products.bulk_images.urls'
])

{{--
    "Fetch from URLs" panel — the second tab of the bulk-images and bulk-logos
    screens. Both use this; the only real differences are the endpoints, the
    field that names a row (SKU vs brand name) and the lang prefix.

    Behaviour lives in resources/js/admin/bulk-image-urls.js. The row list and
    its CSS are shared with the file-upload tab (components/bulk-images.css),
    so the two tabs look like one screen rather than two.

    Note there is no drop zone and no preview image before saving: the SERVER
    downloads these, in small batches, with the browser driving the loop.
--}}
<div {{ $attributes }}
     x-data="bulkImageUrls({{ \Illuminate\Support\Js::from([
        'matchUrl'     => $matchUrl,
        'fetchUrl'     => $fetchUrl,
        'batchSize'    => $batchSize,
        'pendingCount' => $pendingCount,
        'refKey'       => $refKey,
        'labels'       => __($lang),
     ]) }})">

    {{-- How it works --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="card-title">{{ __($lang.'.how_title') }}</div>
            <ol class="import-guide-list mt-2">
                <li>{{ __($lang.'.how_1') }}</li>
                <li>{{ __($lang.'.how_2') }}</li>
                <li>{{ __($lang.'.how_3') }}</li>
            </ol>
        </div>
    </div>

    {{-- Source 1: what the import already declared. --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="card-title">{{ __($lang.'.source_pending') }}</div>
            @if ($pendingCount > 0)
                <div class="flex flex-wrap items-center justify-between gap-3 mt-2">
                    <p class="page-sub">
                        {{ __($lang.'.pending_hint', ['count' => number_format($pendingCount)]) }}
                    </p>
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-primary"
                            :disabled="phase === 'matching' || phase === 'fetching'"
                            @click="loadPending()">
                        <x-icon name="link" class="w-4 h-4" />
                        {{ __($lang.'.load_pending') }}
                    </button>
                </div>
            @else
                <p class="page-sub mt-2">{{ __($lang.'.no_pending') }}</p>
            @endif
        </div>
    </div>

    {{-- Source 2: pasted pairs. --}}
    <div class="card mb-4">
        <div class="card-body">
            <div class="card-title">{{ __($lang.'.source_paste') }}</div>
            <div class="form-stack mt-3">
                <div>
                    <label class="pos-label" for="bulk-url-paste">
                        {{ __($lang.'.paste_label') }}
                    </label>
                    <textarea id="bulk-url-paste"
                              class="pos-input bulk-url-paste"
                              rows="6"
                              x-model="pasteText"
                              placeholder="{{ __($lang.'.paste_placeholder') }}"></textarea>
                    <p class="page-sub mt-2">{{ __($lang.'.paste_hint') }}</p>
                </div>
                <div class="flex justify-end">
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-primary"
                            :disabled="phase === 'matching' || phase === 'fetching'"
                            @click="matchPaste()">
                        {{ __($lang.'.paste_button') }}
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- Checking spinner --}}
    <div class="card mb-4" x-show="phase === 'matching'" x-cloak>
        <div class="card-body flex items-center gap-3">
            <span class="inline-flex animate-spin"><x-icon name="refresh" class="w-4 h-4" /></span>
            <span>{{ __($lang.'.matching') }}</span>
        </div>
    </div>

    {{-- Nothing to do --}}
    <template x-if="phase === 'ready' && matched.length === 0">
        <x-alert type="warning" class="mb-4">{{ __($lang.'.nothing_matched') }}</x-alert>
    </template>

    {{-- The host blocks outbound HTTP. Said once, loudly, instead of letting
         every remaining link time out one at a time. --}}
    <div x-show="blocked" x-cloak class="mb-4">
        <x-alert type="danger">
            <strong>{{ __($lang.'.blocked_title') }}</strong>
            <span class="block mt-1">{{ __($lang.'.blocked_body') }}</span>
            <span class="block mt-3">
                <button type="button"
                        class="pos-btn pos-btn-sm pos-btn-ghost"
                        @click="continueAnyway()">
                    {{ __($lang.'.blocked_continue') }}
                </button>
            </span>
        </x-alert>
    </div>

    {{-- Results / preview --}}
    <div class="card mb-4" x-show="phase === 'ready' || phase === 'fetching' || phase === 'done'" x-cloak>
        <div class="card-body">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                <div class="card-title" x-text="summaryText"></div>
                <div class="flex items-center gap-2">
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            x-show="matched.some(r => r.status === 'error')"
                            @click="downloadFailed()">
                        <x-icon name="download" class="w-4 h-4" />
                        {{ __($lang.'.failed_download') }}
                    </button>
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            x-show="unmatched.length > 0"
                            @click="downloadUnmatched()">
                        <x-icon name="download" class="w-4 h-4" />
                        {{ __($lang.'.unmatched_download') }}
                    </button>
                    <button type="button"
                            class="pos-btn pos-btn-sm pos-btn-ghost"
                            @click="reset()">
                        {{ __($lang.'.clear') }}
                    </button>
                </div>
            </div>

            {{-- Matched list --}}
            <div x-show="matched.length > 0">
                <div class="bulk-img-section-title" x-text="matchedTitle"></div>
                <ul class="bulk-img-list">
                    <template x-for="(row, i) in matched" :key="i">
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
                                    {{-- For brands the name IS the identifier,
                                         so repeating it here would be noise. --}}
                                    <template x-if="refKey !== 'name'">
                                        <span class="mono" x-text="row[refKey]"></span>
                                    </template>
                                    <template x-if="refKey !== 'name'"><span>·</span></template>
                                    <span class="bulk-img-url" :title="row.url" x-text="row.url"></span>
                                    <span class="bulk-img-replace"
                                          x-show="row.has_image && row.status === 'ready'"
                                          x-cloak>{{ __($lang.'.has_image_note') }}</span>
                                </span>
                                <span class="bulk-img-sub bulk-img-err"
                                      x-show="row.status === 'error'"
                                      x-cloak
                                      x-text="errorLabel(row.error)"></span>
                            </span>
                            <span class="bulk-img-status">
                                <span x-show="row.status === 'fetching'" class="inline-flex animate-spin"><x-icon name="refresh" class="w-3.5 h-3.5" /></span>
                                <span x-show="row.status === 'done'" class="bulk-img-ok"><x-icon name="check" class="w-4 h-4" /></span>
                                <span x-show="row.status === 'error'" class="bulk-img-err"><x-icon name="x" class="w-4 h-4" /></span>
                            </span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- Skipped list --}}
            <div x-show="unmatched.length > 0" class="mt-4">
                <div class="bulk-img-section-title" x-text="unmatchedTitle"></div>
                <ul class="bulk-img-list bulk-img-list--muted">
                    <template x-for="(row, i) in unmatched" :key="i">
                        <li class="bulk-img-row is-unmatched">
                            <span class="bulk-img-thumb"><x-icon name="link" class="w-4 h-4" /></span>
                            <span class="bulk-img-meta">
                                <span class="bulk-img-name" x-text="row.line"></span>
                                <span class="bulk-img-sub" x-text="reasonLabel(row.reason)"></span>
                            </span>
                        </li>
                    </template>
                </ul>
            </div>

            {{-- Fetch action / progress --}}
            <div class="flex items-center justify-end gap-3 mt-4" x-show="matched.length > 0">
                <span class="page-sub" x-show="phase === 'fetching'" x-cloak x-text="fetchingText"></span>
                <span class="page-sub bulk-img-done-text" x-show="phase === 'done'" x-cloak x-text="doneText"></span>
                <button type="button"
                        class="pos-btn pos-btn-sm pos-btn-primary"
                        x-show="phase !== 'done'"
                        :disabled="phase === 'fetching'"
                        @click="fetchAll()">
                    <x-icon name="download" class="w-4 h-4" />
                    <span x-text="fetchButtonText"></span>
                </button>
                <button type="button"
                        class="pos-btn pos-btn-sm pos-btn-ghost"
                        x-show="phase === 'done'"
                        x-cloak
                        @click="reset()">
                    {{ __($lang.'.start_over') }}
                </button>
            </div>
        </div>
    </div>
</div>
