<?php

namespace App\Http\Requests;

use App\Services\FinanceDocuments;
use Illuminate\Foundation\Http\FormRequest;

class FinanceConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(FinanceDocuments::class)->canConfigure($this->user());
    }

    public function rules(): array
    {
        $rules = ['company_id' => ['required', 'integer'], 'revision' => ['required', 'integer', 'min:0'],
            'logo'             => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:512', 'dimensions:max_width=3000,max_height=3000'],
            'remove_logo'      => ['nullable', 'boolean'], 'settings' => ['required', 'array:display_name,address,registration_number,tax_number,email,phone,bank_details,po_terms,invoice_terms,footer,po_prefix,po_suffix,po_padding,po_next,invoice_prefix,invoice_suffix,invoice_padding,invoice_next']];
        foreach (['display_name', 'registration_number', 'tax_number', 'email', 'phone', 'po_prefix', 'po_suffix', 'invoice_prefix', 'invoice_suffix'] as $field) {
            $rules['settings.'.$field] = ['nullable', 'string', 'max:150'];
        }
        foreach (['po_prefix', 'po_suffix', 'invoice_prefix', 'invoice_suffix'] as $field) {
            $rules['settings.'.$field] = ['nullable', 'string', 'max:100'];
        }
        foreach (['address', 'bank_details', 'po_terms', 'invoice_terms', 'footer'] as $field) {
            $rules['settings.'.$field] = ['nullable', 'string', 'max:4000'];
        }
        foreach (['po', 'invoice'] as $kind) {
            $rules['settings.'.$kind.'_padding'] = ['required', 'integer', 'between:1,10'];
            $rules['settings.'.$kind.'_next'] = ['required', 'integer', 'between:1,999999999'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return ['logo.max' => 'Use a logo smaller than 512 KB.', 'revision.required' => 'Reopen configuration before saving.', 'settings.array' => 'An unsupported document setting was supplied.'];
    }
}
