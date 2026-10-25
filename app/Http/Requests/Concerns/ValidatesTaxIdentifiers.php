<?php

namespace App\Http\Requests\Concerns;

/**
 * Shared rules for the GSTIN / PAN identifiers that appear on both the
 * customer and supplier forms.
 *
 * Both are fixed-width alphanumeric codes — GSTIN is 15 characters, PAN is
 * 10 — so a typo (a dropped digit, a pasted space, a stray dash) is caught
 * on entry rather than surfacing later on a tax return. We check length and
 * character class only, deliberately NOT the full structural regex: those
 * layouts are India-specific and have changed before, and this product ships
 * worldwide. A blank value stays perfectly valid — most stores never fill
 * these in at all.
 *
 * Both identifiers are canonically uppercase; {@see normalizeTaxIdentifiers()}
 * trims and upper-cases so "22aaaaa0000a1z5" stores the same as the printed
 * form and two records for the same business never look different.
 */
/** @mixin \Illuminate\Foundation\Http\FormRequest */
trait ValidatesTaxIdentifiers
{
    /** @return array<int, mixed> */
    protected function gstinRules(): array
    {
        return ['nullable', 'string', 'size:15', 'alpha_num'];
    }

    /** @return array<int, mixed> */
    protected function panRules(): array
    {
        return ['nullable', 'string', 'size:10', 'alpha_num'];
    }

    /** @return array<string, string> */
    protected function taxIdentifierMessages(): array
    {
        return [
            'gstin.size'      => __('errors.gstin_size'),
            'gstin.alpha_num' => __('errors.gstin_alpha_num'),
            'pan.size'        => __('errors.pan_size'),
            'pan.alpha_num'   => __('errors.pan_alpha_num'),
        ];
    }

    /**
     * Trim + upper-case the identifiers before validation, so a lowercase or
     * space-padded paste doesn't fail a rule it actually satisfies.
     */
    protected function normalizeTaxIdentifiers(): void
    {
        $merge = [];

        foreach (['gstin', 'pan'] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = trim((string) $this->input($key));
            $merge[$key] = $value === '' ? null : strtoupper($value);
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
