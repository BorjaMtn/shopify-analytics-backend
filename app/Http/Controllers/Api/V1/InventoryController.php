<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\InventoryAnalysisService; // <-- Importa tu servicio
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon; // <-- Importa Carbon si vas a manejar fechas aquí

class InventoryController extends Controller
{
    // Inyecta el servicio en el constructor
    public function __construct(protected InventoryAnalysisService $inventoryAnalysisService)
    {
    }

    /**
     * Maneja la petición para obtener los insights de inventario.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInsights(Request $request)
    {
        $user = $request->user(); // Obtiene el usuario autenticado (asegúrate que la ruta tiene middleware 'auth:sanctum' o similar)

        // Obtener el identificador del período de la query string (?period=7d, ?period=30d, etc.)
        // Usar '7d' como valor por defecto si no se proporciona.
        $periodIdentifier = $request->query('period', '7d'); // <-- Obtiene el ?period= de la URL

        // Validar el $periodIdentifier si es necesario (opcional)
        $allowedPeriods = ['7d', '30d', 'this_month', 'last_month']; // Ejemplo
        if (!in_array($periodIdentifier, $allowedPeriods)) {
             return response()->json(['message' => 'Invalid period specified.'], 400); // Bad Request
        }

        Log::info("InventoryController: Solicitud de insights recibida para user_id: {$user->id}, Periodo: {$periodIdentifier}");

        // Calcular startDate y endDate aquí o pasar el identifier al servicio
        // Si el servicio ya lo hace, podemos simplificar.
        // El InventoryAnalysisService ya calcula fechas internamente, así que solo pasamos el identifier.

        // --- Calcular Fechas (Si NO lo hiciera el servicio) ---
        // Esto es un ejemplo, si tu servicio ya maneja el identifier, no necesitas esto aquí.
        // $endDate = Carbon::now()->endOfDay();
        // switch ($periodIdentifier) {
        //     case '30d':
        //         $startDate = Carbon::now()->subDays(29)->startOfDay();
        //         break;
        //      // ... otros casos ...
        //     case '7d':
        //     default:
        //          $startDate = Carbon::now()->subDays(6)->startOfDay();
        //          break;
        // }
        // $insights = $this->inventoryAnalysisService->analyzeProductInventory($user, $startDate, $endDate);


        // --- Llamar al Servicio pasando el identifier (Asumiendo que el servicio lo maneja) ---
        // Nota: Necesitaríamos modificar ligeramente InventoryAnalysisService para aceptar $periodIdentifier
        // O mantenemos el cálculo de fechas aquí y pasamos $startDate, $endDate al servicio.
        // Vamos a asumir por ahora que pasamos las fechas calculadas como estaba antes:

        $endDate = Carbon::now()->endOfDay();
         switch ($periodIdentifier) {
             case '30d':
                 $startDate = Carbon::now()->subDays(29)->startOfDay();
                 break;
             case 'this_month':
                 $startDate = Carbon::now()->startOfMonth()->startOfDay();
                 break;
             case 'last_month':
                 $startDate = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
                 $endDate = Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay();
                 break;
             case '7d':
             default:
                 $startDate = Carbon::now()->subDays(6)->startOfDay();
                 break;
         }

         // Llamar al método del servicio
         $insights = $this->inventoryAnalysisService->analyzeProductInventory($user, $startDate, $endDate);


        // Devolver los insights como respuesta JSON
        return response()->json($insights);
    }
}