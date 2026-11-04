<?php

namespace App\Http\Requests;

use App\Services\HrWorkflows;
use Illuminate\Foundation\Http\FormRequest;

class AutomaticPayrollRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && app(HrWorkflows::class)->enabled() && app(HrWorkflows::class)->manager($this->user(), 'payroll');
    }

    public function rules(): array
    {
        return ['company_id' => ['required', 'integer', 'min:1'], 'employee_id' => ['required', 'integer', 'min:1'], 'period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'], 'pay_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_to'], 'request_id' => [$this->routeIs('*.automatic.store') ? 'required' : 'nullable', 'uuid'], 'calculation_hash' => [$this->routeIs('*.automatic.store') ? 'required' : 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/'], 'base_pay' => ['prohibited'], 'components' => ['prohibited'], 'values' => ['prohibited']];
    }

    public function messages(): array
    {
        return ['calculation_hash.required' => 'Calculate and review the current preview before saving.', 'base_pay.prohibited' => 'Salary is loaded from the approved employee compensation profile.'];
    }
}
