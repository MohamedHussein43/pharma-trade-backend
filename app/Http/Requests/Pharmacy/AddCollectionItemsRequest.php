<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class AddCollectionItemsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'items'                    => 'required|array|min:1',
            'items.*.inventory_id'     => 'required|integer|exists:pharmacy_drug_inventory,id',
            'items.*.quantity'         => 'required|integer|min:1',
            'items.*.discount_pct'     => 'nullable|numeric|min:0|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'items.*.inventory_id.exists'   => 'Drug #:position not found in your inventory.',
            'items.*.quantity.min'          => 'Quantity for drug #:position must be at least 1.',
            'items.*.discount_pct.min'      => 'Discount for drug #:position cannot be negative.',
            'items.*.discount_pct.max'      => 'Discount for drug #:position cannot exceed 100%.',
        ];
    }
}
