<?php

namespace App\Http\Requests;

class PublicVendorRegistrationRequest extends VendorWorkspaceRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['company_id'],$rules['comment'],$rules['reference']);

        return [...$rules, 'email'=>'required|email|max:255', 'company_registry'=>'required|string|max:255', 'contact_person'=>'required|string|max:255'];
    }
}
