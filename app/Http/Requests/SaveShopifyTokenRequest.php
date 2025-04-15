<?php

namespace App\Http\Requests;

// --- ¡ASEGÚRATE QUE ESTA LÍNEA EXISTE Y ES CORRECTA! ---
use Illuminate\Foundation\Http\FormRequest;
// ---------------------------------------------------------
use Illuminate\Support\Facades\Auth;

// --- ¡ASEGÚRATE QUE AQUÍ DICE 'extends FormRequest'! ---
class SaveShopifyTokenRequest extends FormRequest
// ----------------------------------------------------
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Confiamos en el middleware 'auth:sanctum' de la ruta para la autorización.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => [
                'required',
                'string',
                'max:255',
                // 'regex:/^[a-zA-Z0-9\-]+\.myshopify\.com$/' // Ejemplo Regex
            ],
            'access_token' => ['required', 'string'],
        ];
    }

     /**
      * Get custom messages for validator errors.
      *
      * @return array
      */
     public function messages(): array
     {
         return [
             'shop_domain.required' => 'El dominio de la tienda Shopify es obligatorio.',
             // 'shop_domain.regex' => 'El formato del dominio de Shopify no es válido.',
             'access_token.required' => 'El token de acceso de Shopify es obligatorio.',
         ];
     }
}