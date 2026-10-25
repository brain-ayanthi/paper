@props(['url', 'phone' => null, 'context' => []])
<button type="button" {{ $attributes->class(['pos-btn pos-btn-sm pos-btn-ghost']) }}
        x-data="{ busy: false }"
        :disabled="busy"
        @click="
            let target = window.open('about:blank', '_blank');
            let supplied = @js($phone ?: '');
            if (!supplied) supplied = window.prompt(@js(__('whatsapp.actions.phone_prompt'))) || '';
            if (!supplied) { if (target) target.close(); return; }
            busy = true;
            $http.post(@js($url), Object.assign({ phone: supplied }, @js($context)))
                .then((response) => {
                    const data = response?.data?.data || response?.data || response;
                    if (data?.click_url) {
                        if (target) target.location = data.click_url;
                        else window.location.assign(data.click_url);
                    }
                    else if (target) target.close();
                    $store.toasts?.push({ type: 'success', message: response?.data?.message || @js(__('whatsapp.flash.queued')) });
                })
                .catch((error) => { if (target) target.close(); $store.toasts?.push({ type: 'error', message: error?.message || @js(__('whatsapp.errors.provider_failed')) }); })
                .finally(() => { busy = false; });
        ">
    <x-icon name="external" class="w-4 h-4" />
    <span x-text="busy ? @js(__('whatsapp.actions.sending')) : @js(__('whatsapp.actions.send'))"></span>
</button>
