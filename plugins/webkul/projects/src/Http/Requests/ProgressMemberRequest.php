<?php

namespace Webkul\Project\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Webkul\Project\Policies\ProgressAccess;

class ProgressMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(ProgressAccess::class)->manageMembers($this->user(), $this->route('progressProject'));
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'exists:users,id'], 'can_submit' => ['required', 'boolean'], 'can_configure' => ['required', 'boolean']];
    }

    public function messages(): array
    {
        return ['user_id.exists' => 'Select an existing team member.'];
    }
}
