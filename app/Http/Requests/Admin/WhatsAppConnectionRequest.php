<?php

namespace App\Http\Requests\Admin;

use App\Models\WhatsAppConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WhatsAppConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $existing = $this->route('connection');
        $credentialRequired = fn (string $key) => Rule::requiredIf(
            fn () => $this->input('mode') === 'automatic'
                && empty($this->input("credentials.{$key}"))
                && empty(data_get($existing?->credentials ?? [], $key)),
        );

        return [
            'name' => ['required', 'string', 'max:191'],
            'provider' => ['required', 'string', 'max:32'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'sender_phone' => ['nullable', 'string', 'max:32'],
            'mode' => ['required', Rule::in(['automatic', 'click_to_chat'])],
            'graph_version' => ['nullable', 'regex:/^v\d+\.\d+$/', 'max:12'],
            'retention_days' => ['nullable', 'integer', 'between:30,3650'],
            'auto_send_receipts' => ['sometimes', 'boolean'],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:4096'],
            'credentials.phone_number_id' => [$credentialRequired('phone_number_id'), 'nullable', 'string', 'max:191'],
            'credentials.waba_id' => ['nullable', 'string', 'max:191'],
            'credentials.access_token' => [$credentialRequired('access_token'), 'nullable', 'string', 'max:4096'],
            'credentials.app_secret' => [$credentialRequired('app_secret'), 'nullable', 'string', 'max:4096'],
            'credentials.verify_token' => [$credentialRequired('verify_token'), 'nullable', 'string', 'max:4096'],
            'templates' => ['nullable', 'array'],
            'templates.*.name' => ['nullable', 'string', 'max:512', 'regex:/^[a-z0-9_]*$/'],
            'templates.*.language' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z_\-]*$/'],
        ];
    }

    public function connectionData(?WhatsAppConnection $existing = null): array
    {
        $incoming = array_filter((array) $this->validated('credentials', []), fn ($value) => $value !== null && $value !== '');
        $credentials = array_replace($existing?->credentials ?? [], $incoming);

        return [
            'name' => trim((string) $this->validated('name')),
            'provider' => (string) $this->validated('provider'),
            'store_id' => $this->filled('store_id') ? (int) $this->validated('store_id') : null,
            'sender_phone' => $this->validated('sender_phone') ?: null,
            'credentials' => $credentials,
            'settings' => [
                'mode' => (string) $this->validated('mode'),
                'graph_version' => $this->validated('graph_version') ?: config('services.whatsapp.meta_graph_version', 'v23.0'),
                'retention_days' => (int) ($this->validated('retention_days') ?: config('services.whatsapp.retention_days', 365)),
                'auto_send_receipts' => $this->boolean('auto_send_receipts'),
                'templates' => (array) $this->validated('templates', []),
            ],
        ];
    }
}
