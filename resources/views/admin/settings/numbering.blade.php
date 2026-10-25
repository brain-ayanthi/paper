<x-admin-layout
    active="settings"
    :title="__('settings.numbering.title')"
    :crumbs="[
        ['label' => __('settings.crumb_parent')],
        ['label' => __('settings.title'), 'href' => route('admin.settings.index')],
        ['label' => __('settings.numbering.title')],
    ]">

    @php
        $v = [
            'sale_number_format' => old('sale_number_format', $company->sale_number_format ?: $defaultSale),
            'hold_number_format' => old('hold_number_format', $company->hold_number_format ?: $defaultHold),
            'quotation_number_format' => old('quotation_number_format', $company->quotation_number_format ?: $defaultQuotation),
            'refund_number_format' => old('refund_number_format', $company->refund_number_format ?: $defaultRefund),
            'sku_format'         => old('sku_format',         $company->sku_format ?: $defaultSku),
            'barcode_prefix'     => old('barcode_prefix',     $company->barcode_prefix ?: $defaultBarcodePrefix),
        ];
    @endphp

    <div class="page-wide"
         x-data="{
             sale: @js($v['sale_number_format']),
             hold: @js($v['hold_number_format']),
             quotation: @js($v['quotation_number_format']),
             refund: @js($v['refund_number_format']),
             sku:  @js($v['sku_format']),
             barcodePrefix: @js($v['barcode_prefix']),
             defaultSale: @js($defaultSale),
             defaultHold: @js($defaultHold),
             defaultQuotation: @js($defaultQuotation),
             defaultRefund: @js($defaultRefund),
             defaultSku:  @js($defaultSku),
             defaultBarcodePrefix: @js($defaultBarcodePrefix),
             today: new Date(),

             // Best-effort client-side preview that mirrors
             // `\App\Support\NumberFormat::render` for the Y/m/d/seq
             // placeholders. Lets the admin sanity-check their format
             // string without saving.
             preview(template) {
                 const t  = this.today;
                 const Y  = String(t.getFullYear());
                 const y  = Y.slice(-2);
                 const m  = String(t.getMonth() + 1).padStart(2, '0');
                 const d  = String(t.getDate()).padStart(2, '0');
                 const seq = 42;
                 let s = String(template || '')
                     .replaceAll('{store}', @js($sampleStore->code ?: 'MAIN'))
                     .replaceAll('{Y}',  Y)
                     .replaceAll('{y}',  y)
                     .replaceAll('{m}',  m)
                     .replaceAll('{d}',  d)
                     .replaceAll('{Ym}',  Y + m)
                     .replaceAll('{Ymd}', Y + m + d)
                     .replaceAll('{seq}', String(seq));
                 s = s.replace(/\{seq:(\d+)\}/g, (_, n) => String(seq).padStart(Math.min(9, +n), '0'));
                 return s;
             },

             // Sample EAN-13 for a given in-store prefix — prefix + a
             // counter padded to 12 digits + the check digit. Mirrors
             // App\Actions\Products\GenerateProductBarcode so what the
             // admin previews here is what the Generate button produces.
             barcodePreview(prefix) {
                 const p = String(prefix || '').replace(/[^0-9]/g, '').slice(0, 7) || '20';
                 const body = (p + '1'.padStart(12 - p.length, '0')).slice(0, 12);
                 let sum = 0;
                 for (let i = 0; i < 12; i++) {
                     sum += (i % 2 === 0) ? +body[i] : +body[i] * 3;
                 }
                 const check = (10 - (sum % 10)) % 10;
                 return body + check;
             },
         }">

        <form method="POST" action="{{ route('admin.settings.numbering.update') }}" data-ajax-form>
            @csrf
            @method('PATCH')

            <div class="page-header mb-6">
                <div class="flex items-start gap-3">
                    <a href="{{ route('admin.settings.index') }}" class="pos-btn pos-btn-sm pos-btn-ghost" aria-label="{{ __('settings.title') }}">
                        <x-icon name="back" class="w-4 h-4" />
                    </a>
                    <div>
                        <h1 class="page-title">{{ __('settings.numbering.title') }}</h1>
                        <p class="page-sub">{{ __('settings.numbering.sub') }}</p>
                    </div>
                </div>
                <button type="submit" class="pos-btn pos-btn-sm pos-btn-primary">
                    <x-icon name="check" class="w-4 h-4" />
                    {{ __('settings.numbering.actions.save') }}
                </button>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_360px] gap-5 items-start">
                <div class="space-y-5">
                    {{-- Sale format --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('settings.numbering.sections.sale') }}</div>
                            <div class="card-title-sub">{{ __('settings.numbering.sections.sale_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.format') }}</span>
                                <input type="text"
                                       name="sale_number_format"
                                       class="pos-input mono"
                                       x-model="sale"
                                       maxlength="191"
                                       placeholder="{{ $defaultSale }}">
                                <span class="field-help">{{ __('settings.numbering.fields.format_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !sale.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="preview(sale.trim() || defaultSale)"></span>
                            </div>

                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost mt-2"
                                    @click="sale = defaultSale">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>

                    {{-- Hold format --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('settings.numbering.sections.hold') }}</div>
                            <div class="card-title-sub">{{ __('settings.numbering.sections.hold_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.format') }}</span>
                                <input type="text"
                                       name="hold_number_format"
                                       class="pos-input mono"
                                       x-model="hold"
                                       maxlength="191"
                                       placeholder="{{ $defaultHold }}">
                                <span class="field-help">{{ __('settings.numbering.fields.format_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !hold.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="preview(hold.trim() || defaultHold)"></span>
                            </div>

                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost mt-2"
                                    @click="hold = defaultHold">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>

                    {{-- Quotation format --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('quotations.settings.numbering_title') }}</div>
                            <div class="card-title-sub">{{ __('quotations.settings.numbering_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.format') }}</span>
                                <input type="text"
                                       name="quotation_number_format"
                                       class="pos-input mono"
                                       x-model="quotation"
                                       maxlength="191"
                                       placeholder="{{ $defaultQuotation }}">
                                <span class="field-help">{{ __('settings.numbering.fields.format_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !quotation.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="preview(quotation.trim() || defaultQuotation)"></span>
                            </div>

                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost mt-2" @click="quotation = defaultQuotation">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>

                    {{-- Refund format --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('settings.numbering.sections.refund') }}</div>
                            <div class="card-title-sub">{{ __('settings.numbering.sections.refund_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.format') }}</span>
                                <input type="text"
                                       name="refund_number_format"
                                       class="pos-input mono"
                                       x-model="refund"
                                       maxlength="191"
                                       placeholder="{{ $defaultRefund }}">
                                <span class="field-help">{{ __('settings.numbering.fields.format_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !refund.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="preview(refund.trim() || defaultRefund)"></span>
                            </div>

                            <button type="button" class="pos-btn pos-btn-sm pos-btn-ghost mt-2" @click="refund = defaultRefund">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>

                    {{-- Product SKU format. {store} is deliberately absent
                         from the help text here: products are company-level,
                         so a store code in a SKU would be a lie the moment
                         the item is stocked in a second branch. --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('settings.numbering.sections.sku') }}</div>
                            <div class="card-title-sub">{{ __('settings.numbering.sections.sku_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.format') }}</span>
                                <input type="text"
                                       name="sku_format"
                                       class="pos-input mono"
                                       x-model="sku"
                                       maxlength="64"
                                       placeholder="{{ $defaultSku }}">
                                <span class="field-help">{{ __('settings.numbering.fields.sku_format_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !sku.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="preview(sku.trim() || defaultSku)"></span>
                            </div>

                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost mt-2"
                                    @click="sku = defaultSku">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>

                    {{-- In-store barcode prefix. Only used by the product
                         form's "Generate" button, for non-standard items
                         with no manufacturer barcode. --}}
                    <div class="card">
                        <div class="card-header"><div>
                            <div class="card-title">{{ __('settings.numbering.sections.barcode') }}</div>
                            <div class="card-title-sub">{{ __('settings.numbering.sections.barcode_sub') }}</div>
                        </div></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label is-required">{{ __('settings.numbering.fields.barcode_prefix') }}</span>
                                <input type="text"
                                       name="barcode_prefix"
                                       class="pos-input mono"
                                       x-model="barcodePrefix"
                                       inputmode="numeric"
                                       maxlength="7"
                                       placeholder="{{ $defaultBarcodePrefix }}">
                                <span class="field-help">{{ __('settings.numbering.fields.barcode_prefix_help') }}</span>
                            </label>

                            <div class="cashier-numbering-preview" :class="{ 'is-empty': !barcodePrefix.trim() }">
                                <span class="cashier-numbering-preview-label">{{ __('settings.numbering.preview') }}</span>
                                <span class="cashier-numbering-preview-value mono tnum" x-text="barcodePreview(barcodePrefix.trim() || defaultBarcodePrefix)"></span>
                            </div>

                            <button type="button"
                                    class="pos-btn pos-btn-sm pos-btn-ghost mt-2"
                                    @click="barcodePrefix = defaultBarcodePrefix">
                                <x-icon name="refresh" class="w-3.5 h-3.5" />
                                {{ __('settings.numbering.reset_default') }}
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Placeholder reference card. Each token has a copy
                     button on the right — `navigator.clipboard.writeText`
                     puts the placeholder on the clipboard so the admin
                     doesn't have to retype `{seq:04}` etc. by hand. --}}
                <div class="card"
                     x-data="{
                         async copy(token) {
                             try {
                                 await navigator.clipboard.writeText(token);
                                 this.$store.toasts?.push({
                                     type: 'success',
                                     message: 'Copied ' + token,
                                     duration: 1500,
                                 });
                             } catch (e) {
                                 // Fallback for non-HTTPS contexts: temporarily
                                 // create a hidden input and select+execCommand.
                                 const el = document.createElement('input');
                                 el.value = token;
                                 document.body.appendChild(el);
                                 el.select();
                                 try { document.execCommand('copy'); } catch (_) {}
                                 el.remove();
                                 this.$store.toasts?.push({ type: 'success', message: 'Copied ' + token, duration: 1500 });
                             }
                         },
                     }">
                    <div class="card-header"><div>
                        <div class="card-title">{{ __('settings.numbering.sections.placeholders') }}</div>
                        <div class="card-title-sub">{{ __('settings.numbering.sections.placeholders_sub') }}</div>
                    </div></div>
                    <div class="card-body">
                        <ul class="cashier-numbering-tokens">
                            @foreach ($placeholders as $p)
                                <li class="cashier-numbering-token">
                                    <code class="mono">{{ $p['token'] }}</code>
                                    <span>{{ $p['desc'] }}</span>
                                    <button type="button"
                                            class="cashier-numbering-copy"
                                            @click="copy({{ \Illuminate\Support\Js::from($p['token']) }})"
                                            :title="@js(__('settings.numbering.copy'))"
                                            :aria-label="@js(__('settings.numbering.copy'))">
                                        <x-icon name="copy" class="w-3.5 h-3.5" />
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                        <div class="cashier-numbering-note">
                            {{ __('settings.numbering.note') }}
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</x-admin-layout>
