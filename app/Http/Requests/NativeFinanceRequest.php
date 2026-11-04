<?php

namespace App\Http\Requests;

use App\Services\NativeFinance;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NativeFinanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $finance = app(NativeFinance::class);
        $kind = $this->route('kind');
        if (! $finance->available($this->user(), $kind)) {
            return false;
        }
        if ($id = $this->route('record')) {
            return $this->user()->can('update', $finance->record($this->user(), $kind, (int) $id));
        }
        $class = $finance->model($kind);

        return $this->user()->can('create', $class) && $this->user()->can('view', new $class);
    }

    public function rules(): array
    {
        if (($this->is('*/confirm') || $this->is('*/cancel'))) {
            return ['revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/']];
        }
        $edit = (bool) $this->route('record');
        $kind = $this->route('kind');
        $rules = ['request_id' => [$edit ? 'prohibited' : 'required', 'uuid'], 'revision' => [$edit ? 'required' : 'prohibited', 'string', 'regex:/^[a-f0-9]{64}$/'], 'values.company_id' => ['required', 'integer', 'min:1']];
        if ($kind === 'items') {
            $fields = 'company_id,name,reference,description,type,price,cost,category_id,uom_id,uom_po_id,enable_sales,enable_purchase';
            $rules += ['values.name' => ['required', 'string', 'max:255'], 'values.reference' => ['nullable', 'string', 'max:100'], 'values.description' => ['nullable', 'string', 'max:4000'], 'values.type' => ['required', Rule::in(['goods', 'service'])]];
            foreach (['price', 'cost'] as $field) {
                $rules['values.'.$field] = ['required', 'numeric', 'min:0', 'max:99999999', 'decimal:0,4'];
            }
            foreach (['category_id', 'uom_id', 'uom_po_id'] as $field) {
                $rules['values.'.$field] = ['required', 'integer', 'min:1'];
            }
            foreach (['enable_sales', 'enable_purchase'] as $field) {
                $rules['values.'.$field] = ['required', 'boolean'];
            }
        } else {
            $fields = 'company_id,partner_id,currency_id,date,reference';
            $rules += ['values.partner_id' => ['required', 'integer', 'min:1'], 'values.currency_id' => ['required', 'integer', 'min:1'], 'values.date' => ['required', 'date_format:Y-m-d'], 'values.reference' => ['nullable', 'string', 'max:255']];
            if ($kind !== 'purchase-orders') {
                $fields .= ',journal_id';
                $rules['values.journal_id'] = ['required', 'integer', 'min:1'];
            }
            if ($kind === 'payments') {
                $fields .= ',amount,payment_method_line_id';
                $rules += ['values.amount' => ['required', 'numeric', 'gt:0', 'max:99999999', 'decimal:0,4'], 'values.payment_method_line_id' => ['required', 'integer', 'min:1']];
            } else {
                $fields .= ',due_date,terms,lines';
                if ($kind === 'purchase-orders') {
                    $fields .= ',work_order_id,work_order_version_id';
                    $rules['values.work_order_version_id'] = ['required_with:values.work_order_id', 'nullable', 'integer', 'min:1'];
                    $rules['values.work_order_id'] = ['nullable', 'integer', 'min:1'];
                }
                $rules['values.lines.*.work_order_line_key'] = [$kind === 'purchase-orders' ? 'nullable' : 'prohibited', 'uuid'];
                $rules += ['values.due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:values.date'], 'values.terms' => ['nullable', 'string', 'max:8000'], 'values.lines' => ['required', 'array', 'min:1', 'max:100'], 'values.lines.*' => ['required', 'array:item_id,description,quantity,unit_price,discount,account_id,tax_ids,work_order_line_key'], 'values.lines.*.item_id' => ['required', 'integer', 'min:1'], 'values.lines.*.description' => ['required', 'string', 'max:2000'], 'values.lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,2'], 'values.lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999', 'decimal:0,2'], 'values.lines.*.discount' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'], 'values.lines.*.tax_ids' => ['nullable', 'array', 'max:10'], 'values.lines.*.tax_ids.*' => ['integer', 'distinct', 'min:1'], 'values.lines.*.account_id' => [$kind === 'purchase-orders' ? 'nullable' : 'required', 'integer', 'min:1']];
            }
        }
        $rules['values'] = ['required', 'array:'.$fields];

        return $rules;
    }

    public function messages(): array
    {
        return ['values.array' => 'The form contains an unsupported field.', 'revision.required' => 'Reopen this document before saving.', 'request_id.required' => 'A unique submission key is required to prevent duplicates.'];
    }
}
