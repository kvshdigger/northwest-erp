<?php

namespace App\Http\Requests;

use App\Services\WorkOrders;
use Illuminate\Foundation\Http\FormRequest;

class WorkOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(WorkOrders::class)->financialAccess($this->user());
    }

    public function rules(): array
    {
        if ($this->is('*/approve')) {
            return ['revision' => ['required', 'integer', 'min:1']];
        }

        return [
            'request_id'              => [$this->isMethod('POST') ? 'required' : 'prohibited', 'uuid'],
            'revision'                => [$this->isMethod('PUT') ? 'required' : 'prohibited', 'integer', 'min:1'],
            'values'                  => ['required', 'array:project_id,partner_id,currency_id,reference,title,reason,routes,lines'],
            'values.project_id'       => ['required', 'integer', 'min:1'], 'values.partner_id' => ['required', 'integer', 'min:1'], 'values.currency_id' => ['required', 'integer', 'min:1'], 'values.reference' => ['required', 'string', 'max:120'],
            'values.title'            => ['required', 'string', 'max:255'], 'values.reason' => ['required', 'string', 'max:2000'],
            'values.routes'           => ['required', 'array', 'list', 'min:1', 'max:100'], 'values.routes.*' => ['array:key,name,chainage_from,chainage_to'],
            'values.routes.*.key'     => ['required', 'uuid', 'distinct'], 'values.routes.*.name' => ['required', 'string', 'max:120'], 'values.routes.*.chainage_from' => ['required', 'string', 'max:30'], 'values.routes.*.chainage_to' => ['required', 'string', 'max:30'],
            'values.lines'            => ['required', 'array', 'list', 'min:1', 'max:100'], 'values.lines.*' => ['array:key,route_key,item_id,description,quantity,maincon_rate,subcon_rate'],
            'values.lines.*.key'      => ['required', 'uuid', 'distinct'], 'values.lines.*.route_key' => ['required', 'uuid'], 'values.lines.*.item_id' => ['required', 'integer', 'min:1'], 'values.lines.*.description' => ['required', 'string', 'max:2000'],
            'values.lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:1000000000', 'decimal:0,4'], 'values.lines.*.maincon_rate' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,4'], 'values.lines.*.subcon_rate' => ['required', 'numeric', 'min:0', 'max:1000000000', 'decimal:0,4'],
        ];
    }

    public function messages(): array
    {
        return ['values.array' => 'An unsupported Work Order field was supplied.', 'revision.required' => 'Reopen this version before saving.', 'values.reason.required' => 'Record the source document or variation reason.', 'request_id.required' => 'Supply a unique submission key to prevent duplicate Work Orders.'];
    }
}
