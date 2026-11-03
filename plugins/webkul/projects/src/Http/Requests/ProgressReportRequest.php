<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Project\Policies\ProgressAccess;

class ProgressReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->submit($this->user(), $this->route('progressProject'));
    }

    public function rules(): array
    {
        return [
            'route_id'              => ['required', 'integer', 'min:1'],
            'report_date'           => ['required', 'date_format:Y-m-d'],
            'team'                  => ['required', 'string', 'max:120'],
            'location'              => ['nullable', 'string', 'max:255'],
            'submission_key'        => ['required', 'uuid'],
            'entries'               => ['required', 'array', 'list', 'min:1', 'max:100'],
            'entries.*'             => ['required', 'array:activity_id,version,inputs,team,location'],
            'entries.*.activity_id' => ['required', 'integer', 'min:1'],
            'entries.*.version'     => ['required', 'integer', 'min:1'],
            'entries.*.inputs'      => ['present', 'array', 'max:30'],
            'entries.*.team'        => ['nullable', 'string', 'max:120'],
            'entries.*.location'    => ['nullable', 'string', 'max:255'],
            'status'                => ['prohibited'],
            'created_by'            => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return ['submission_key.required' => 'Supply a unique submission key so retries do not create duplicate reports.', 'entries.*.version.required' => 'Each entry must identify its published configuration version.'];
    }
}
