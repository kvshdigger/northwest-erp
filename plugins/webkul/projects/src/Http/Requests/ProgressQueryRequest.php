<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Project\Policies\ProgressAccess;

class ProgressQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->view($this->user(), $this->route('progressProject'));
    }

    public function rules(): array
    {
        return ['route_id' => ['nullable', 'integer', 'min:1'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'], 'page' => ['nullable', 'integer', 'min:1']];
    }

    public function messages(): array
    {
        return ['date_to.after_or_equal' => 'The end date must be on or after the start date.'];
    }
}
