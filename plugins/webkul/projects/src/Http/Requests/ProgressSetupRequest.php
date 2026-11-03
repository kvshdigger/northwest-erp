<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webkul\Project\Policies\ProgressAccess;

class ProgressSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->configure($this->user(), $this->route('progressProject'));
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name_key' => mb_strtolower(trim($this->input('name')))]);
        }
    }

    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:120'],
            'name_key' => ['required', 'string', 'max:120', Rule::unique('projects_progress_routes')->where('project_id', $this->route('progressProject')->id)],
        ];
    }

    public function messages(): array
    {
        return ['name_key.unique' => 'This project already has a route with that name.'];
    }
}
