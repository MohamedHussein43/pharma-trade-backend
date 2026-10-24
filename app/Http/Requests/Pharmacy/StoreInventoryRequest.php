<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class StoreInventoryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'drug_id'            => 'required|exists:drugs,id',
            'quantity_available' => 'required|integer|min:1',
            'unit_price'         => 'required|numeric|min:0.01',
            'expiry_date'        => 'nullable|date|after:today',
            'batch_number'       => 'nullable|string|max:100',
        ];
    }
}
