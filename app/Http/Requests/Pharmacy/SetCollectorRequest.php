<?php

namespace App\Http\Requests\Pharmacy;

use Illuminate\Foundation\Http\FormRequest;

class SetCollectorRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'collector_name'  => 'required|string|max:200',
            'collector_phone' => 'required|string|max:30',
        ];
    }
}
