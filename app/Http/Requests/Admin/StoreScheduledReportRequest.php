<?php

namespace App\Http\Requests\Admin;

use App\Models\ScheduledReport;
use App\Support\ReportRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduledReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Upper bound on how many addresses one schedule may deliver to (SEC #58). */
    public const MAX_RECIPIENTS = 20;

    public function rules(): array
    {
        return [
            'report_key'         => ['required', 'string', Rule::in(ReportRegistry::keys())],
            'name'               => ['required', 'string', 'max:120'],
            'parameters'         => ['nullable', 'array'],
            'frequency'          => ['required', Rule::in(ScheduledReport::FREQUENCIES)],
            'day_of_week'        => ['nullable', 'integer', 'between:1,7', 'required_if:frequency,weekly'],
            'day_of_month'       => ['nullable', 'integer', 'between:1,31', 'required_if:frequency,monthly'],
            'time_of_day'        => ['required', 'date_format:H:i'],
            'format'             => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            // Capped (SEC #58). There was no maximum, so anyone holding
            // `reports.schedule` could attach store financials to hundreds of
            // external addresses on a recurring cadence — bulk exfiltration
            // dressed up as a feature. 20 is generous for the real use case
            // (an owner, an accountant, a couple of managers).
            'recipients_email'   => ['required', 'array', 'min:1', 'max:'.self::MAX_RECIPIENTS],
            'recipients_email.*' => ['email', 'max:190'],
            'subject_template'   => ['nullable', 'string', 'max:200'],
            'message_template'   => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'recipients_email.max'      => __('reports.schedule.errors.recipients_max', ['max' => self::MAX_RECIPIENTS]),
            'recipients_email.required' => __('reports.schedule.errors.recipients_required'),
            'recipients_email.min'      => __('reports.schedule.errors.recipients_required'),
            'recipients_email.*.email'  => __('reports.schedule.errors.recipient_invalid'),
        ];
    }
}
