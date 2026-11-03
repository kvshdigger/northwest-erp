<?php

namespace App\Http\Requests;

use App\Services\BusinessWorkspace;
use Illuminate\Foundation\Http\FormRequest;

class BusinessRecordsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->is_active && app(BusinessWorkspace::class)->section($this->user(), $this->route('module'), $this->route('section'))['state'] === 'available';
    }

    public function rules(): array
    {
        return ['search' => ['nullable', 'string', 'max:120'], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['search.max' => 'Use no more than 120 characters for a search.'];
    }
}
