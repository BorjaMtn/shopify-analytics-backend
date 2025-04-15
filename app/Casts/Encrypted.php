<?php

namespace App\Casts; // <-- Nuevo Namespace

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException; // Para manejar errores de desencriptación

class Encrypted implements CastsAttributes
{
    /**
     * Cast the given value.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value // Valor de la BD (encriptado)
     * @param  array  $attributes
     * @return string|null // Valor desencriptado
     */
    public function get($model, string $key, $value, array $attributes): ?string
    {
        if (is_null($value)) {
            return null;
        }
        try {
            // Intenta desencriptar
            return Crypt::decryptString($value);
        } catch (DecryptException $e) {
            // Si falla la desencriptación (ej. cambio de APP_KEY, dato corrupto)
            // Loguea el error y devuelve null o un valor por defecto
            \Illuminate\Support\Facades\Log::error("Error decrypting attribute {$key} for model " . get_class($model) . " ID: {$model->getKey()}", ['exception' => $e]);
            return null; // O podrías lanzar una excepción personalizada
        }
    }

    /**
     * Prepare the given value for storage.
     *
     * @param  \Illuminate\Database\Eloquent\Model  $model
     * @param  string  $key
     * @param  mixed  $value // Valor a guardar (sin encriptar)
     * @param  array  $attributes
     * @return string|null // Valor encriptado para la BD
     */
    public function set($model, string $key, $value, array $attributes): ?string
    {
        // Solo encripta si el valor no es nulo
        return isset($value) ? Crypt::encryptString($value) : null;
    }
}