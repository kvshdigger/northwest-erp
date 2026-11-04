<?php

namespace App\Http\Requests;

use App\Services\NativeHr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Employee\Enums\MaritalStatus;

class NativeHrRequest extends FormRequest
{
    public function authorize(): bool
    {
        $hr = app(NativeHr::class);
        $kind = $this->route('kind');
        if (! $hr->available($this->user(), $kind)) {
            return false;
        }
        if ($id = $this->route('record')) {
            return $this->user()->can('update', $hr->record($this->user(), $kind, (int) $id));
        }
        $model = $hr->model($kind);

        return $this->user()->can('create', $model) && $this->user()->can('view', new $model);
    }

    protected function prepareForValidation(): void
    {
        $values = $this->input('values');
        if ($this->route('kind') === 'employees' && ! $this->route('record') && is_array($values) && ! array_key_exists('marital', $values)) {
            $this->merge(['values' => [...$values, 'marital' => MaritalStatus::Single->value]]);
        }
    }

    public function rules(): array
    {
        $hr = app(NativeHr::class);
        $fields = $hr->fields($this->route('kind'));
        $editing = (bool) $this->route('record');
        $rules = [
            'values'     => ['required', 'array:'.implode(',', array_column($fields, 0))],
            'request_id' => [$editing ? 'prohibited' : 'required', 'uuid'],
            'revision'   => [$editing ? 'required' : 'prohibited', 'string', 'regex:/^[a-f0-9]{64}$/'],
        ];
        foreach ($fields as $field) {
            [$key, , $type] = $field;
            $validation = ($field[4] ?? false) || $type === 'checkbox' ? ['required'] : ['nullable'];
            if (in_array($key, ['company_id', 'department_id', 'job_id', 'work_location_id', 'parent_id', 'coach_id', 'manager_id', 'employee_id', 'holiday_status_id'], true)) {
                $validation = [...$validation, 'integer', 'min:1'];
            } elseif ($type === 'checkbox') {
                $validation[] = 'boolean';
            } elseif ($type === 'date') {
                $validation[] = 'date_format:Y-m-d';
            } else {
                $validation = [...$validation, 'string', 'max:255'];
                if ($type === 'email') {
                    $validation[] = 'email';
                }
            }
            $rules['values.'.$key] = $validation;
        }
        $rules['values.company_id'][] = Rule::in($hr->companyIds($this->user()));
        if ($this->route('kind') === 'employees') {
            $rules['values.gender'][] = Rule::in(['male', 'female', 'other']);
            $rules['values.marital'][] = Rule::in(['single', 'married', 'divorced', 'widowed']);
        }

        if ($this->route('kind') === 'leave') {
            $rules['values.request_date_to'][] = 'after_or_equal:values.request_date_from';
            $rules['values.request_date_to'][] = 'before_or_equal:'.now()->addYears(5)->format('Y-m-d');
            $rules['values.request_date_from'][] = 'after_or_equal:'.now()->subYears(5)->format('Y-m-d');
            $rules['values.request_date_from_period'] = ['required_if:values.request_unit_half,true', 'nullable', Rule::in(['morning', 'afternoon'])];
        }

        return $rules;
    }

    public function attributes(): array
    {
        $attributes = [];
        foreach (app(NativeHr::class)->fields($this->route('kind')) as $field) {
            $attributes['values.'.$field[0]] = strtolower($field[1]);
        }

        return $attributes;
    }

    public function messages(): array
    {
        return ['revision.required' => 'Reopen this profile before saving so its current revision is available.', 'values.array' => 'The form contains an unsupported field.', 'request_id.required' => 'Supply a unique submission key to prevent duplicate profiles.'];
    }
}
