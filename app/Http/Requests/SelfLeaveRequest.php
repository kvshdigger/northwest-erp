<?php

namespace App\Http\Requests;

use App\Services\HrWorkflows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelfLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && app(HrWorkflows::class)->enabled() && (bool) app(HrWorkflows::class)->ownEmployee($this->user());
    }

    public function rules(): array
    {
        return ['request_id' => ['required', 'uuid'], 'values' => ['required', 'array:holiday_status_id,request_date_from,request_date_to,request_unit_half,request_date_from_period,private_name'], 'values.holiday_status_id' => ['required', 'integer', 'min:1'], 'values.request_date_from' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now()->subYears(1)->toDateString()], 'values.request_date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:values.request_date_from', 'before_or_equal:'.now()->addYears(2)->toDateString()], 'values.request_unit_half' => ['required', 'boolean'], 'values.request_date_from_period' => ['required_if:values.request_unit_half,true', 'nullable', Rule::in(['morning', 'afternoon'])], 'values.private_name' => ['nullable', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['values.array' => 'Use only the leave request fields; employee identity is supplied by your account.'];
    }
}
