<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Project\Policies\ProgressAccess;

class ProgressDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->configure($this->user(), $this->route('progressProject'));
    }

    public function rules(): array
    {
        return [
            'revision'                        => ['required', 'integer', 'min:0'],
            'configuration'                   => ['required', 'array:name,unit,fields,formula,outputField'],
            'configuration.name'              => ['required', 'string', 'max:120'],
            'configuration.unit'              => ['nullable', 'string', 'max:30'],
            'configuration.fields'            => ['present', 'array', 'list', 'max:30'],
            'configuration.fields.*'          => ['required', 'array:key,name,type,required,min,max'],
            'configuration.fields.*.key'      => ['required', 'string', 'max:60', 'regex:/^[A-Za-z_][A-Za-z_0-9]*$/', 'distinct:strict'],
            'configuration.fields.*.name'     => ['nullable', 'string', 'max:120'],
            'configuration.fields.*.type'     => ['required', 'in:Character,Integer,Float,Datetime'],
            'configuration.fields.*.required' => ['required', 'boolean'],
            'configuration.fields.*.min'      => ['nullable', 'numeric', 'between:-999999999999999999,999999999999999999'],
            'configuration.fields.*.max'      => ['nullable', 'numeric', 'between:-999999999999999999,999999999999999999'],
            'configuration.formula'           => ['nullable', 'string', 'max:1000'],
            'configuration.outputField'       => ['nullable', 'string', 'max:60'],
        ];
    }

    public function messages(): array
    {
        return ['revision.required' => 'Supply the revision shown when the settings were opened.', 'configuration.required' => 'Supply the activity settings to save as a draft.'];
    }
}
