<?php

namespace App\Actions\Customers;

use App\Http\Requests\Admin\CustomerRequest;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\User;
use App\Services\Excel\SpreadsheetReader;
use App\Support\PhoneNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Streaming CSV / XLSX customer importer with the same preview-and-map
 * workflow as the category and product importers.
 *
 * Existing customers are matched by code, normalized phone, or normalized
 * email. Ledger-derived balances and loyalty points are intentionally absent
 * from {@see TARGET_FIELDS}; an import must never manufacture financial state.
 * One address may be supplied per row, with repeated customer rows used for
 * additional labeled addresses. Unmentioned existing addresses are preserved.
 */
class ImportCustomersFromCsv
{
    public const TARGET_FIELDS = [
        'code',
        'name',
        'phone',
        'whatsapp_phone',
        'whatsapp_opt_out',
        'email',
        'dob',
        'gender',
        'is_business',
        'business_name',
        'gstin',
        'pan',
        'tax_registration_number',
        'customer_group',
        'default_discount_percent',
        'credit_limit',
        'notes',
        'address_label',
        'address_line1',
        'address_line2',
        'address_city',
        'address_state',
        'address_postal_code',
        'address_country_code',
        'address_landmark',
        'address_is_default',
        'is_active',
    ];

    public const PREVIEW_ROWS = 10;

    public function __construct(
        private SpreadsheetReader $reader,
        private CreateCustomer $createCustomer,
        private UpdateCustomer $updateCustomer,
    ) {}

    /**
     * @return array{headers: array<int, string>, suggested_map: array<string, ?string>, rows: array<int, array<int, string>>, total_columns: int}
     */
    public function preview(string $path): array
    {
        $headers = [];
        $sample = [];

        foreach ($this->reader->rows($path, self::PREVIEW_ROWS + 1) as $i => $row) {
            if ($i === 0) {
                $headers = $row;

                continue;
            }

            $sample[] = $row;
        }

        $suggested = [];
        foreach ($headers as $header) {
            $suggested[$header] = $this->suggestField($header);
        }

        return [
            'headers' => $headers,
            'suggested_map' => $suggested,
            'rows' => $sample,
            'total_columns' => count($headers),
        ];
    }

    /**
     * @param  array<int|string, ?string>  $mapping
     * @return array{created: int, updated: int, skipped: int, errors: array<int, array{row: int, message: string}>}
     */
    public function commit(string $path, array $mapping, ?int $userId = null): array
    {
        do_action('customers.before_import', $path, $mapping);

        $mapping = $this->normalizeMapping($mapping);
        if (! in_array('name', $mapping, true)) {
            throw new \RuntimeException(__('customers.import.errors.name_required'));
        }

        $groups = $this->groupLookup();
        $identities = $this->identityLookups();
        $user = $userId === null ? null : User::find($userId);
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        $rowNum = 0;

        foreach ($this->reader->rows($path) as $i => $cells) {
            $rowNum++;
            if ($i === 0) {
                continue;
            }

            $errorCount = count($errors);
            $payload = $this->buildPayload($cells, $mapping, $groups, $errors, $rowNum);
            if ($payload === null) {
                continue;
            }
            $address = $this->buildAddress($cells, $mapping, $errors, $rowNum);
            if (count($errors) > $errorCount) {
                $skipped++;

                continue;
            }

            $payload = apply_filters('customers.import.row', $payload, $cells, $mapping);
            $address = apply_filters('customers.import.address', $address, $cells, $mapping);

            if ($address !== null) {
                $addressValidator = $this->validateAddress($address);
                if ($addressValidator->fails()) {
                    $errors[] = ['row' => $rowNum, 'message' => $addressValidator->errors()->first()];
                    $skipped++;

                    continue;
                }
            }

            [$existing, $identityConflict] = $this->findExisting($payload, $identities);
            if ($identityConflict) {
                $errors[] = ['row' => $rowNum, 'message' => __('customers.import.errors.identity_conflict')];
                $skipped++;

                continue;
            }

            // A blank Code means "auto-generate" for new customers. On an
            // update matched by phone/email it must not erase the existing code.
            if ($existing && array_key_exists('code', $payload) && $payload['code'] === null) {
                unset($payload['code']);
            }

            $payload = $this->applyDefaultsAndBusinessRules($payload, $existing);
            $validator = $this->validateRow($payload, $existing);
            if ($validator->fails()) {
                $errors[] = ['row' => $rowNum, 'message' => $validator->errors()->first()];
                $skipped++;

                continue;
            }

            $before = $existing?->replicate();

            try {
                $saved = DB::transaction(function () use ($existing, $payload, $address, $user) {
                    $customer = $existing
                        // null preserves every existing address; the imported
                        // address is merged separately below.
                        ? ($this->updateCustomer)($existing, $payload, null, $user)
                        : ($this->createCustomer)($payload, [], $user);

                    if ($address !== null) {
                        $this->upsertAddress($customer, $address);
                    }

                    return $customer;
                });

                $existing ? $updated++ : $created++;
            } catch (QueryException) {
                $errors[] = [
                    'row' => $rowNum,
                    'message' => __('customers.import.errors.row_failed', ['row' => $rowNum]),
                ];
                $skipped++;

                continue;
            }

            $this->refreshIdentityLookups($identities, $saved, $before);
        }

        $summary = compact('created', 'updated', 'skipped', 'errors');
        do_action('customers.after_import', $summary);

        return $summary;
    }

    /**
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $mapping
     * @param  array<string, int>  $groups
     * @param  array<int, array{row:int, message:string}>  $errors
     * @return array<string, mixed>|null
     */
    private function buildPayload(array $cells, array $mapping, array $groups, array &$errors, int $rowNum): ?array
    {
        $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);
        $name = trim((string) $get('name'));

        if ($name === '') {
            return null;
        }

        $payload = ['name' => $name];

        foreach (['code', 'email', 'dob', 'business_name', 'tax_registration_number', 'notes'] as $field) {
            if ($this->isMapped($mapping, $field)) {
                $payload[$field] = $this->nullableString($get($field));
            }
        }

        if (array_key_exists('email', $payload) && $payload['email'] !== null) {
            $payload['email'] = strtolower($payload['email']);
        }

        foreach (['phone', 'whatsapp_phone'] as $field) {
            if ($this->isMapped($mapping, $field)) {
                $payload[$field] = PhoneNormalizer::normalize($get($field));
            }
        }

        foreach (['gstin', 'pan'] as $field) {
            if ($this->isMapped($mapping, $field)) {
                $raw = $this->nullableString($get($field));
                $payload[$field] = $raw === null ? null : strtoupper($raw);
            }
        }

        if ($this->isMapped($mapping, 'gender')) {
            $payload['gender'] = $this->normalizeGender($get('gender'));
        }

        foreach (['default_discount_percent', 'credit_limit'] as $field) {
            if ($this->isMapped($mapping, $field)) {
                $payload[$field] = $this->nullableString($get($field));
            }
        }

        foreach (['is_business', 'whatsapp_opt_out', 'is_active'] as $field) {
            if (! $this->isMapped($mapping, $field)) {
                continue;
            }

            $value = $this->parseBoolean($get($field), $field, $errors, $rowNum);
            if ($value !== null) {
                $payload[$field] = $value;
            }
        }

        if ($this->isMapped($mapping, 'customer_group')) {
            $raw = trim((string) $get('customer_group'));
            if ($raw === '') {
                $payload['customer_group_id'] = null;
            } else {
                $key = Str::lower($raw);
                if (! isset($groups[$key])) {
                    $errors[] = [
                        'row' => $rowNum,
                        'message' => __('customers.import.errors.unknown_group', ['name' => $raw]),
                    ];
                } else {
                    $payload['customer_group_id'] = $groups[$key];
                }
            }
        }

        return $payload;
    }

    /**
     * Build one address from the flat row. Additional addresses use repeated
     * customer rows with the same identity and a different Address label.
     *
     * @param  array<int, string>  $cells
     * @param  array<int, string>  $mapping
     * @param  array<int, array{row:int, message:string}>  $errors
     * @return array<string, mixed>|null
     */
    private function buildAddress(array $cells, array $mapping, array &$errors, int $rowNum): ?array
    {
        $get = fn (string $field) => $this->valueFor($cells, $mapping, $field);
        $fields = [
            'address_label' => 'label',
            'address_line1' => 'line1',
            'address_line2' => 'line2',
            'address_city' => 'city',
            'address_state' => 'state',
            'address_postal_code' => 'postal_code',
            'address_country_code' => 'country_code',
            'address_landmark' => 'landmark',
        ];

        $address = [];
        foreach ($fields as $source => $target) {
            if ($this->isMapped($mapping, $source)) {
                $address[$target] = $this->nullableString($get($source));
            }
        }

        if (isset($address['country_code'])) {
            $address['country_code'] = strtoupper($address['country_code']);
        }

        if ($this->isMapped($mapping, 'address_is_default')) {
            $isDefault = $this->parseBoolean($get('address_is_default'), 'address_is_default', $errors, $rowNum);
            if ($isDefault !== null) {
                $address['is_default'] = $isDefault;
            }
        }

        $hasContent = collect($address)
            ->except('is_default')
            ->contains(fn ($value) => $value !== null && trim((string) $value) !== '');

        return $hasContent ? $address : null;
    }

    /** @param array<string, mixed> $payload */
    private function applyDefaultsAndBusinessRules(array $payload, ?Customer $existing): array
    {
        if ($existing === null) {
            $payload += [
                'is_business' => false,
                'whatsapp_opt_out' => false,
                'is_active' => true,
            ];
        }

        $isBusiness = array_key_exists('is_business', $payload)
            ? (bool) $payload['is_business']
            : (bool) ($existing?->is_business ?? false);

        if (! $isBusiness) {
            foreach (['business_name', 'gstin', 'pan', 'tax_registration_number'] as $field) {
                $payload[$field] = null;
            }
        }

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function validateRow(array $payload, ?Customer $existing): \Illuminate\Contracts\Validation\Validator
    {
        $id = $existing?->id;
        $candidate = array_merge($existing?->only([
            'code', 'name', 'phone', 'whatsapp_phone', 'whatsapp_opt_out', 'email',
            'dob', 'gender', 'is_business', 'business_name', 'gstin', 'pan',
            'tax_registration_number', 'customer_group_id', 'default_discount_percent',
            'credit_limit', 'notes', 'is_active',
        ]) ?? [], $payload);

        return Validator::make($candidate, [
            'code' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'code')->whereNull('deleted_at')->ignore($id)],
            'name' => ['required', 'string', 'max:191'],
            'phone' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'phone')->whereNull('deleted_at')->ignore($id)],
            'whatsapp_phone' => ['nullable', 'string', 'max:32', Rule::unique('customers', 'whatsapp_phone')->whereNull('deleted_at')->ignore($id)],
            'whatsapp_opt_out' => ['boolean'],
            'email' => ['nullable', 'email:rfc', 'max:191', Rule::unique('customers', 'email')->whereNull('deleted_at')->ignore($id)],
            'dob' => ['nullable', 'date', 'before:today'],
            'gender' => ['nullable', Rule::in(CustomerRequest::GENDERS)],
            'is_business' => ['boolean'],
            'business_name' => ['nullable', 'string', 'max:191', 'required_if:is_business,1'],
            'gstin' => ['nullable', 'string', 'size:15', 'alpha_num'],
            'pan' => ['nullable', 'string', 'size:10', 'alpha_num'],
            'tax_registration_number' => ['nullable', 'string', 'max:64'],
            'customer_group_id' => ['nullable', 'integer'],
            'default_discount_percent' => ['nullable', 'numeric', 'between:0,100'],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'is_active' => ['boolean'],
        ], [
            'business_name.required_if' => __('customers.validation.business_name_required'),
            'phone.unique' => __('customers.validation.phone_unique'),
            'whatsapp_phone.unique' => __('customers.validation.whatsapp_unique'),
            'email.unique' => __('customers.validation.email_unique'),
            'gstin.size' => __('errors.gstin_size'),
            'gstin.alpha_num' => __('errors.gstin_alpha_num'),
            'pan.size' => __('errors.pan_size'),
            'pan.alpha_num' => __('errors.pan_alpha_num'),
        ]);
    }

    /** @param array<string, mixed> $address */
    private function validateAddress(array $address): \Illuminate\Contracts\Validation\Validator
    {
        return Validator::make($address, [
            'label' => ['nullable', 'string', 'max:32'],
            'line1' => ['nullable', 'string', 'max:191'],
            'line2' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'landmark' => ['nullable', 'string', 'max:191'],
            'is_default' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * Merge one imported address without replacing the customer's address
     * collection. A label is the stable key; unlabeled rows update the default
     * address, or the sole address, and otherwise create a new row.
     *
     * @param  array<string, mixed>  $address
     */
    private function upsertAddress(Customer $customer, array $address): void
    {
        $existingAddress = null;
        $label = $address['label'] ?? null;

        if ($label !== null && $label !== '') {
            $existingAddress = $customer->addresses()
                ->whereRaw('LOWER(label) = ?', [strtolower((string) $label)])
                ->first();
        }

        if (
            $existingAddress === null
            && ($label === null || $label === '')
            && ($address['is_default'] ?? false)
        ) {
            $existingAddress = $customer->addresses()->where('is_default', true)->first();
        }

        if ($existingAddress === null && ($label === null || $label === '')) {
            $addresses = $customer->addresses()->limit(2)->get();
            if ($addresses->count() === 1) {
                $existingAddress = $addresses->first();
            }
        }

        if ($address['is_default'] ?? false) {
            $customer->addresses()
                ->when($existingAddress, fn ($query) => $query->where('id', '!=', $existingAddress->id))
                ->update(['is_default' => false]);
        }

        if ($existingAddress) {
            $existingAddress->update($address);

            return;
        }

        $customer->addresses()->create($address + ['is_default' => false]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{code:array<string,int>,phone:array<string,int>,email:array<string,int>}  $lookups
     * @return array{0:?Customer,1:bool}
     */
    private function findExisting(array $payload, array $lookups): array
    {
        $ids = [];

        foreach (['code', 'phone', 'email'] as $field) {
            $value = $payload[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            $key = $field === 'phone' ? (string) $value : Str::lower((string) $value);
            if (isset($lookups[$field][$key])) {
                $ids[$lookups[$field][$key]] = true;
            }
        }

        if (count($ids) > 1) {
            return [null, true];
        }

        $id = array_key_first($ids);

        return [$id === null ? null : Customer::find($id), false];
    }

    /** @return array{code:array<string,int>,phone:array<string,int>,email:array<string,int>} */
    private function identityLookups(): array
    {
        $lookups = ['code' => [], 'phone' => [], 'email' => []];

        Customer::query()->get(['id', 'code', 'phone', 'email'])->each(function (Customer $customer) use (&$lookups) {
            $this->addIdentity($lookups, $customer);
        });

        return $lookups;
    }

    /**
     * @param  array{code:array<string,int>,phone:array<string,int>,email:array<string,int>}  $lookups
     */
    private function refreshIdentityLookups(array &$lookups, Customer $saved, ?Customer $before): void
    {
        if ($before !== null) {
            foreach (['code', 'phone', 'email'] as $field) {
                $value = $before->{$field};
                if ($value === null || $value === '') {
                    continue;
                }
                $key = $field === 'phone' ? (string) $value : Str::lower((string) $value);
                if (($lookups[$field][$key] ?? null) === $saved->id) {
                    unset($lookups[$field][$key]);
                }
            }
        }

        $this->addIdentity($lookups, $saved);
    }

    /**
     * @param  array{code:array<string,int>,phone:array<string,int>,email:array<string,int>}  $lookups
     */
    private function addIdentity(array &$lookups, Customer $customer): void
    {
        foreach (['code', 'phone', 'email'] as $field) {
            $value = $customer->{$field};
            if ($value === null || $value === '') {
                continue;
            }

            $key = $field === 'phone' ? (string) $value : Str::lower((string) $value);
            $lookups[$field][$key] = $customer->id;
        }
    }

    /** @return array<string, int> */
    private function groupLookup(): array
    {
        return CustomerGroup::query()->active()->pluck('id', 'name')
            ->mapWithKeys(fn ($id, $name) => [Str::lower((string) $name) => (int) $id])
            ->all();
    }

    private function suggestField(string $header): ?string
    {
        $aliases = [
            'code' => ['code', 'customercode', 'customerid', 'accountcode'],
            'name' => ['name', 'customer', 'customername', 'fullname'],
            'phone' => ['phone', 'mobile', 'mobilenumber', 'phonenumber'],
            'whatsapp_phone' => ['whatsapp', 'whatsappphone', 'whatsappnumber'],
            'whatsapp_opt_out' => ['whatsappoptout', 'nowhatsapp', 'disablewhatsapp'],
            'email' => ['email', 'emailaddress'],
            'dob' => ['dob', 'dateofbirth', 'birthdate', 'birthday'],
            'gender' => ['gender', 'sex'],
            'is_business' => ['business', 'isbusiness', 'businesscustomer'],
            'business_name' => ['businessname', 'company', 'companyname'],
            'gstin' => ['gstin', 'gstnumber', 'gstno'],
            'pan' => ['pan', 'pannumber', 'panno'],
            'tax_registration_number' => ['taxregistrationnumber', 'taxregistration', 'taxnumber', 'vatnumber'],
            'customer_group' => ['group', 'customergroup', 'pricinggroup'],
            'default_discount_percent' => ['defaultdiscount', 'discount', 'discountpercent', 'defaultdiscountpercent'],
            'credit_limit' => ['creditlimit', 'limit'],
            'notes' => ['notes', 'note', 'remarks', 'comment'],
            'address_label' => ['addresslabel', 'label', 'addresstype'],
            'address_line1' => ['addressline1', 'address1', 'street', 'streetaddress'],
            'address_line2' => ['addressline2', 'address2'],
            'address_city' => ['addresscity', 'city', 'town'],
            'address_state' => ['addressstate', 'state', 'region', 'province'],
            'address_postal_code' => ['addresspostalcode', 'postalcode', 'postcode', 'zipcode', 'zip'],
            'address_country_code' => ['addresscountrycode', 'countrycode', 'country'],
            'address_landmark' => ['addresslandmark', 'landmark'],
            'address_is_default' => ['defaultaddress', 'addressisdefault', 'isdefaultaddress'],
            'is_active' => ['active', 'enabled', 'status', 'isactive'],
        ];

        $needle = $this->canonical($header);
        foreach ($aliases as $field => $candidates) {
            if (in_array($needle, $candidates, true)) {
                return $field;
            }
        }

        return null;
    }

    /** @param array<int|string, ?string> $mapping @return array<int, string> */
    private function normalizeMapping(array $mapping): array
    {
        $out = [];
        foreach ($mapping as $index => $target) {
            if ($target === null || $target === '' || ! in_array($target, self::TARGET_FIELDS, true)) {
                continue;
            }
            $out[(int) $index] = $target;
        }

        return $out;
    }

    private function valueFor(array $cells, array $mapping, string $field): ?string
    {
        $index = array_search($field, $mapping, true);

        return $index === false ? null : ($cells[$index] ?? null);
    }

    private function isMapped(array $mapping, string $field): bool
    {
        return in_array($field, $mapping, true);
    }

    private function nullableString(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : $value;
    }

    private function normalizeGender(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        return match ($this->canonical($value)) {
            'male' => 'male',
            'female' => 'female',
            'other' => 'other',
            'prefernottosay', 'notspecified' => 'prefer_not_to_say',
            default => strtolower($value),
        };
    }

    /** @param array<int, array{row:int, message:string}> $errors */
    private function parseBoolean(?string $value, string $field, array &$errors, int $rowNum): ?bool
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        $normalized = strtolower($value);
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'active', 'enabled'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', 'inactive', 'disabled'], true)) {
            return false;
        }

        $errors[] = [
            'row' => $rowNum,
            'message' => __('customers.import.errors.invalid_boolean', [
                'field' => __('customers.import.fields.'.$field),
                'value' => $value,
            ]),
        ];

        return null;
    }

    private function canonical(string $value): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtolower($value)) ?: '';
    }
}
