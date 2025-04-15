<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class SaveGaPropertyRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Solo usuarios autenticados pueden intentar guardar
        return Auth::check();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'property_id' => [
                'required',
                'string',
                'max:255',
                // Valida que empiece por "properties/" seguido de números
                'regex:/^properties\/\d+$/'
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'property_id.required' => 'El ID de Propiedad GA4 es obligatorio.',
            'property_id.regex' => 'El formato del ID de Propiedad debe ser "properties/123456789".',
        ];
    }
}
