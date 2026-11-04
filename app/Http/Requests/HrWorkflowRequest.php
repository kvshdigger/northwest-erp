<?php

namespace App\Http\Requests;

use App\Services\HrWorkflows;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HrWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active && app(HrWorkflows::class)->enabled();
    }

    public function rules(): array
    {
        if ($this->isMethod('GET')) {
            return ['employee_id' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'self' => ['nullable', 'boolean'], 'expiry' => ['nullable', Rule::in(['all', 'due', 'expired'])]];
        }
        if ($this->route('record')) {
            return ['action' => ['required', Rule::in(['approve', 'reject', 'submit', 'complete', 'reopen', 'withdraw'])], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'max:1000'], 'item' => ['nullable', 'integer', 'min:0', 'max:49']];
        }
        $fields = app(HrWorkflows::class)->fields($this->route('kind'));
        $rules = ['request_id' => ['required', 'uuid'], 'values' => ['required', 'array:'.implode(',', array_column($fields, 'key'))], 'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:10240']];
        foreach ($fields as $field) {
            $key = $field['key'];
            $type = $field['type'];
            $rule = [$field['required'] ? 'required' : 'nullable'];
            $rule = [...$rule, ...match ($type) {
                'select'                  => ['integer', 'min:1'],
                'number'                  => ['numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
                'date'                    => ['date_format:Y-m-d'],
                'datetime-local'          => ['date_format:Y-m-d\TH:i'],
                'checkbox'                => ['accepted'],
                'choice'                  => ['string', Rule::in(array_keys($field['choices']))],
                'allowances'              => ['array', 'max:30'],
                'components', 'checklist' => ['array', 'min:1', 'max:50'],
                default                   => ['string', 'max:1000'],
            }];
            $rules['values.'.$key] = $rule;
        }
        if (in_array($this->route('kind'), ['payroll-rules', 'payroll'], true)) {
            $rules['values.components.*'] = ['required', 'array:label,category,method,value'];
            $rules['values.components.*.label'] = ['required', 'string', 'max:100', 'distinct'];
            $rules['values.components.*.category'] = ['required', Rule::in(['earning', 'deduction', 'employer'])];
            $rules['values.components.*.method'] = ['required', Rule::in(['fixed', 'percent_base', 'manual'])];
            $rules['values.components.*.value'] = ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'];
        }
        if ($this->route('kind') === 'payroll') {
            $rules['values.components'] = ['nullable', 'array', 'max:50'];
        }
        if ($this->route('kind') === 'compensation') {
            foreach (['opening_month'] as $field) {
                $rules['values.'.$field] = ['required', 'integer', 'min:0', 'max:11'];
            }
            $rules['values.allowances'] = ['nullable', 'array', 'max:30'];
            $rules['values.allowances.*'] = ['required', 'array:label,amount,prorate,epf,socso,eis,taxable,classification'];
            $rules['values.allowances.*.label'] = ['required', 'string', 'max:100', 'distinct'];
            $rules['values.allowances.*.amount'] = ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,2'];
            $rules['values.allowances.*.classification'] = ['required', 'string', 'max:500'];
            foreach (['prorate', 'epf', 'socso', 'eis', 'taxable'] as $flag) {
                $rules['values.allowances.*.'.$flag] = ['required', 'boolean'];
            }
            $rules['values.date_of_birth'][] = 'before:today';
            $rules['values.employment_end'][] = 'after_or_equal:values.employment_start';
            $rules['values.effective_to'][] = 'after_or_equal:values.effective_from';
        }
        if ($this->route('kind') === 'checklists') {
            $rules['values.items.*'] = ['required', 'string', 'max:200', 'distinct'];
            $rules['values.process'][] = Rule::in(['onboarding', 'offboarding']);
        }
        if ($this->route('kind') === 'attendance') {
            $rules['values.timezone'][] = 'timezone';
            $rules['values.break_minutes'] = ['required', 'integer', 'min:0', 'max:1440'];
            $rules['values.overtime_minutes'] = ['required', 'integer', 'min:0', 'max:1440'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['values.array' => 'The form includes an unsupported field.', 'request_id.required' => 'A submission key is required to prevent duplicates.', 'reason.required' => 'Enter a reason for this action.', 'attachment.max' => 'Documents must be 10 MB or smaller.'];
    }
}
