<?php

namespace Webkul\Project\Http\Requests;

class ProgressPreviewRequest extends ProgressDraftRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['revision']);
        $rules['inputs'] = ['present', 'array', 'max:30'];

        return $rules;
    }
}
