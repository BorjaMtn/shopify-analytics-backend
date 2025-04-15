<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse; // Asegúrate de importar JsonResponse si lo usas para el tipado

class UserController extends Controller
{
    /**
     * Display the authenticated user's information.
     *
     * @param Request $request
     * @return JsonResponse // O puedes usar \App\Models\User si prefieres tiparlo así
     */
    public function show(Request $request): JsonResponse // Cambiado a método 'show'
    {
        // El middleware 'auth:sanctum' ya asegura que $request->user() está disponible
        return response()->json($request->user());
    }

    // Alternativamente, si prefieres un controlador de acción única:
    /*
    public function __invoke(Request $request): JsonResponse
    {
         return response()->json($request->user());
    }
    */
}