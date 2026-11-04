<?php

namespace App\Http\Requests;

use App\Services\NativeHr;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeaveDecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('kind') === 'leave' && $this->user()->is_active && $this->user()->can('update', app(NativeHr::class)->record($this->user(), 'leave', (int) $this->route('record')));
    }

    public function rules(): array
    {
        return ['action' => ['required', Rule::in(['approve', 'refuse'])], 'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'reason' => ['required', 'string', 'max:1000']];
    }

    public function messages(): array
    {
        return ['reason.required' => 'Enter a reason for this leave decision.', 'revision.required' => 'Reopen the request before deciding.'];
    }
}
