<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
class RegisterPharmacyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // ── User fields ──────────────────────────────────
            'name'                  => ['required', 'string', 'min:3', 'max:150'],
            'email'                 => ['required', 'email', 'unique:users,email'],
            'phone'                 => ['required', 'string', 'unique:users,phone', 'regex:/^(\+20|0)[0-9]{10}$/'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            // ── Pharmacy / business fields ────────────────────
            'business_name'         => ['required', 'string', 'min:3', 'max:200'],
            'licence_number'        => ['required', 'string', 'unique:registration_requests,licence_number'],
            'address'               => ['required', 'string', 'min:10', 'max:500'],
            // ── Zones — now an array ──────────────────────────
            'zones'              => ['required', 'array', 'min:1'],
            'zones.*'            => ['integer', 'exists:zones,id'],
            // ── Licence images ────────────────────────────────
            'licence_image'         => ['required', 'file', 'mimes:jpg,jpeg,png,heic,pdf', 'max:5120'],
            'licence_image_back'    => ['nullable', 'file', 'mimes:jpg,jpeg,png,heic,pdf', 'max:5120'],
            // ── Push notifications ────────────────────────────
            'device_token'          => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique'              => 'This email address is already registered.',
            'phone.unique'              => 'This phone number is already registered.',
            'phone.regex'               => 'Phone must be a valid Egyptian number (e.g. 01012345678).',
            'licence_number.unique'     => 'This licence number has already been submitted.',
            'licence_image.required'    => 'A licence image is required for registration.',
            'licence_image.max'         => 'Licence image must not exceed 5MB.',
            'zone_ids.required'         => 'At least one zone must be selected.',
            'zone_ids.min'              => 'At least one zone must be selected.',
            'zone_ids.*.exists'         => 'One or more selected zones are not valid.',
            'address.required'          => 'The pharmacy address is required.',
            'address.min'               => 'Address must be at least 10 characters.',
            'password.confirmed'        => 'Password confirmation does not match.',
            'password.min'              => 'Password must be at least 8 characters.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'message' => 'Validation failed.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}
