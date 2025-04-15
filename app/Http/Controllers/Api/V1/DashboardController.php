<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request; // <--- Asegúrate que Request está importado
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use App\Services\DashboardService;

class DashboardController extends Controller
{
    public function __construct(protected DashboardService $dashboardService)
    {
    }

    /**
     * Obtiene los datos agregados para el dashboard del usuario autenticado,
     * aceptando un parámetro opcional para el período.
     *
     * @param Request $request La petición HTTP entrante.
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse // El parámetro $request es necesario
    {
        $user = Auth::guard('api')->user();

        if (!$user) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        // --- NUEVO: Leer el parámetro 'period' de la query string ---
        // Si no se proporciona 'period', usará '7d' por defecto.
        // Puedes ajustar los valores permitidos o el default según necesites.
        $periodIdentifier = $request->query('period', '7d');
        Log::info("Acceso al dashboard solicitado por user_id: {$user->id}, Periodo: {$periodIdentifier}");
        // -----------------------------------------------------------

        try {
            // --- MODIFICADO: Pasar el $periodIdentifier al servicio ---
            $dashboardData = $this->dashboardService->getDashboardData($user, $periodIdentifier);
            // --------------------------------------------------------

            return response()->json($dashboardData);

        } catch (\Exception $e) {
             Log::error("Error en DashboardController al obtener datos para user {$user->id} / periodo {$periodIdentifier}: " . $e->getMessage(), ['exception' => $e]);
             return response()->json(['message' => 'Error al obtener los datos del dashboard.'], 500);
        }
    }
}