<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password; // Importar la regla de contraseña

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * En este caso, cualquiera puede intentar registrarse, así que devolvemos true.
     * Si hubiera lógica de autorización (ej. solo admins pueden registrar), iría aquí.
     */
    public function authorize(): bool
    {
        return true; // Cualquiera puede intentar registrarse
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'], // Asegura email único en tabla users
            'password' => [
                'required',
                'string',
                Password::min(8) // Requiere al menos 8 caracteres
                    ->letters()      // Requiere al menos una letra
                    ->mixedCase()    // Requiere mayúsculas y minúsculas
                    ->numbers()      // Requiere al menos un número
                    ->symbols(),     // Requiere al menos un símbolo
                'confirmed' // Requiere que haya un campo 'password_confirmation' con el mismo valor
            ],
        ];
    }
}