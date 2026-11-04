<?php

namespace App\Http\Requests;

use App\Services\HrWorkflows;
use Illuminate\Foundation\Http\FormRequest;

class SelfProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && app(HrWorkflows::class)->enabled() && (bool) app(HrWorkflows::class)->ownEmployee($this->user());
    }

    public function rules(): array
    {
        return ['values' => ['required', 'array:private_email,private_phone,emergency_contact,emergency_phone'], 'values.private_email' => ['nullable', 'email', 'max:255'], 'values.private_phone' => ['nullable', 'string', 'max:255'], 'values.emergency_contact' => ['nullable', 'string', 'max:255'], 'values.emergency_phone' => ['nullable', 'string', 'max:255'], 'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
    }

    public function messages(): array
    {
        return ['values.array' => 'Only your personal contact and emergency contact fields may be updated here.', 'revision.required' => 'Reload your profile before saving.'];
    }
}
