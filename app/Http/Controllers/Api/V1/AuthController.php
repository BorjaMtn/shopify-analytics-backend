<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response; // Import Response facade

// Importaremos estos Form Requests en el siguiente paso
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;

class AuthController extends Controller
{
    /**
     * Handle user registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        // La validación se hace automáticamente por RegisterRequest

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Opcional: Podrías devolver el usuario y un token aquí también si quieres loguear inmediatamente
        // $token = $user->createToken('api-token')->plainTextToken;
        // return response()->json(['user' => $user, 'token' => $token], 201);

        // O simplemente devolver el usuario creado
        return response()->json($user, Response::HTTP_CREATED); // Usa Response::HTTP_CREATED
    }

    /**
     * Handle user login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // La validación se hace automáticamente por LoginRequest

        // Intenta autenticar al usuario
        if (!Auth::attempt($request->only('email', 'password'))) {
            // Si falla, lanza una excepción de validación
            throw ValidationException::withMessages([
                'email' => [trans('auth.failed')], // Mensaje estándar de fallo de autenticación
            ]);
        }

        // Si tiene éxito, obtén el usuario autenticado
        $user = $request->user();

        // Crea un nuevo token Sanctum
        $token = $user->createToken('api-token')->plainTextToken; // Puedes darle un nombre al token

        // Devuelve el token y la información del usuario
        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request): Response // Cambiado a Response
    {
        // Revoca el token actual que se usó para la autenticación
        $request->user()->currentAccessToken()->delete();

        // Devuelve una respuesta sin contenido, indicando éxito
        return response()->noContent(); // Código 204 No Content
    }
}