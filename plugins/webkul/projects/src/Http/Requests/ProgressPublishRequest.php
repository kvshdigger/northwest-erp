<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Project\Policies\ProgressAccess;

class ProgressPublishRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->configure($this->user(), $this->route('progressProject'));
    }

    public function rules(): array
    {
        return ['revision' => ['required', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['revision.required' => 'Supply the saved draft revision to publish.'];
    }
}
