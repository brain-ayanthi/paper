<?php

namespace App\Http\Requests\Admin\Commerce;

use App\Models\CommerceConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCommerceConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('commerce.manage') ?? false;
    }

    public function rules(): array
    {
        $provider = (string) $this->input('provider');
        $creating = ! $this->route('connection');

        return [
            'provider' => ['required', Rule::in(CommerceConnection::PROVIDERS)],
            'name' => ['required', 'string', 'max:255'],
            'shop_url' => ['required', 'string', 'max:2048'],
            'api_version' => [
                Rule::excludeIf($provider !== CommerceConnection::PROVIDER_SHOPIFY),
                'nullable',
                'regex:/^20\d{2}-(01|04|07|10)$/',
            ],
            'credentials' => ['required', 'array'],
            'credentials.consumer_key' => [Rule::requiredIf($creating && $provider === 'woocommerce'), 'nullable', 'string', 'max:191'],
            'credentials.consumer_secret' => [Rule::requiredIf($creating && $provider === 'woocommerce'), 'nullable', 'string', 'max:255'],
            'credentials.client_id' => [Rule::requiredIf($creating && $provider === 'shopify'), 'nullable', 'string', 'max:191'],
            'credentials.client_secret' => [Rule::requiredIf($creating && $provider === 'shopify'), 'nullable', 'string', 'max:255'],
            'credentials.webhook_secret' => ['nullable', 'string', 'max:255'],
            'settings.sync_products' => ['required', 'boolean'],
            'settings.publish_online_store' => [
                Rule::excludeIf($provider !== CommerceConnection::PROVIDER_SHOPIFY),
                'required',
                'boolean',
            ],
            'settings.sync_customers' => ['required', 'boolean'],
            'settings.import_orders' => ['required', 'boolean'],
            'settings.payment_method_id' => [
                Rule::requiredIf($this->boolean('settings.import_orders')),
                'nullable',
                'integer',
                Rule::exists('payment_methods', 'id')->where(
                    fn ($query) => $query->where('is_active', true),
                ),
            ],
            'settings.inventory_master' => ['required', Rule::in(['pos'])],
        ];
    }

    protected function prepareForValidation(): void
    {
        $settings = (array) $this->input('settings', []);
        $settings['sync_products'] = $this->boolean('settings.sync_products');
        if ($this->input('provider') === CommerceConnection::PROVIDER_SHOPIFY) {
            $settings['publish_online_store'] = $this->boolean('settings.publish_online_store');
        } else {
            unset($settings['publish_online_store']);
        }
        $settings['sync_customers'] = $this->boolean('settings.sync_customers');
        $settings['import_orders'] = $this->boolean('settings.import_orders');
        $settings['payment_method_id'] = filled($settings['payment_method_id'] ?? null)
            ? (int) $settings['payment_method_id']
            : null;
        $settings['inventory_master'] = 'pos';

        $this->merge(['settings' => $settings]);
    }

    public function attributes(): array
    {
        return __('commerce.validation.attributes');
    }
}
