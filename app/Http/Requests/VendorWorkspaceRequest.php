<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VendorWorkspaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_active === true;
    }

    public function rules(): array
    {
        return ['name'=>'required|string|max:255', 'account_type'=>'required|in:company,individual', 'email'=>'nullable|email|max:255', 'company_id'=>'required|integer', ...array_fill_keys(['phone', 'mobile', 'website', 'tax_id', 'company_registry', 'reference', 'street1', 'street2', 'city', 'zip'], 'nullable|string|max:255'), 'comment'=>'nullable|string|max:5000'];
    }
}
