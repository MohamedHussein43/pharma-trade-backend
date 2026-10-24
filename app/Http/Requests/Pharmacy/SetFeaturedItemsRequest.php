<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class SetFeaturedItemsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'item_ids'   => 'required|array|min:1|max:2',
            'item_ids.*' => 'integer|exists:pharmacy_collection_items,id',
        ];
    }

    public function messages(): array
    {
        return [
            'item_ids.max'  => 'يمكن اختيار عنصرين كحد أقصى للعرض المميز.',
            'item_ids.min'  => 'يجب اختيار عنصر واحد على الأقل.',
        ];
    }
}