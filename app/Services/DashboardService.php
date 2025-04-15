<?php

namespace App\Services;

use App\Models\User;
use App\Services\ShopifyApiService;
use App\Services\GoogleAnalyticsService;
use App\Services\InventoryAnalysisService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; // <-- Añadir Facade de Cache
use Carbon\Carbon;
use Throwable; // <-- Para capturar excepciones específicas

class DashboardService
{
    // Tiempo de caché en segundos (ej: 15 minutos)
    protected const CACHE_TTL = 900;

    public function __construct(
        protected ShopifyApiService $shopifyApiService,
        protected GoogleAnalyticsService $googleAnalyticsService,
        protected InventoryAnalysisService $inventoryAnalysisService
    ) {}

    /**
     * Obtiene los datos agregados para el dashboard de un usuario para un período específico.
     * Implementa caché para reducir la carga.
     *
     * @param User $user El usuario autenticado.
     * @param string $periodIdentifier Identificador del período. Default '7d'.
     * @return array Un array con los datos del dashboard.
     */
    public function getDashboardData(User $user, string $periodIdentifier = '7d'): array
    {
        // --- 0. Clave de Caché y Comprobación ---
        // La clave debe ser única para cada usuario y período
        $cacheKey = "dashboard_data:user_{$user->id}:period_{$periodIdentifier}";

        // Intenta obtener los datos de la caché primero
        $cachedData = Cache::get($cacheKey);
        if ($cachedData) {
            Log::info("DashboardService: Devolviendo datos cacheados para user_id: {$user->id}, Periodo ID: {$periodIdentifier} (Key: {$cacheKey})");
            // Asegúrate de que la estructura devuelta sea consistente (puede que necesites unserialize si es complejo)
            // Para arrays simples como este, generalmente está bien.
            return is_array($cachedData) ? $cachedData : [];
        }

        Log::info("DashboardService: No cache found for key: {$cacheKey}. Generating data...");

        // --- 1. Calcular Fechas y Etiqueta ---
        // (Misma lógica de fechas que antes, se puede refactorizar a un método privado si se usa en más sitios)
        [$startDate, $endDate, $periodLabel] = $this->calculateDateRange($periodIdentifier);
        Log::info("DashboardService: Iniciando getDashboardData para user_id: {$user->id}, Periodo ID: {$periodIdentifier} ({$startDate->toDateString()} a {$endDate->toDateString()})");

        // Cargar relaciones necesarias UNA VEZ si no están cargadas
        $user->loadMissing(['shop', 'gaConnection']);

        $shopifyMetrics = null;
        $gaMetrics = null;
        $calculatedMetrics = null;
        $inventoryInsights = null;

        $shop = $user->shop; // Acceder una vez
        $gaConnection = $user->gaConnection; // Acceder una vez

        // Try-catch para manejar errores en servicios externos y evitar romper todo el dashboard
        try {
            // --- 2. Obtener datos de Shopify ---
            if ($shop) {
                $shopifyMetrics = $this->fetchShopifyMetrics($shop, $startDate, $endDate);
            } else {
                Log::info("DashboardService: Usuario user_id: {$user->id} no tiene conexión Shopify.");
            }

            // --- 3. Obtener datos de Google Analytics ---
            $gaReady = !is_null($gaConnection) && !is_null($gaConnection->property_id);
            if ($gaReady) {
                 $gaMetrics = $this->fetchGaMetrics($gaConnection, $startDate, $endDate);

                // --- 4. Calcular métricas combinadas (solo si tenemos datos de ambos) ---
                if ($shopifyMetrics && $gaMetrics && isset($gaMetrics['sessions_period'])) {
                    $calculatedMetrics = $this->calculateCombinedMetrics($shopifyMetrics, $gaMetrics['sessions_period']);
                }

                // --- 5. Obtener Insights de Inventario (solo si Shopify está conectado) ---
                // Esta llamada es potencialmente la MÁS PESADA en memoria/tiempo.
                // Debe optimizarse DENTRO de InventoryAnalysisService.
                 if ($shop) { // No necesita doble check, si $shop existe, está conectado
                    Log::info("DashboardService: Iniciando análisis de inventario para user_id: {$user->id}");
                    // !!! PUNTO CRÍTICO DE OPTIMIZACIÓN: Revisar InventoryAnalysisService !!!
                    $inventoryInsights = $this->inventoryAnalysisService->analyzeProductInventory($user, $startDate, $endDate);
                    Log::info("DashboardService: Análisis de inventario completado. Insights encontrados: " . (is_array($inventoryInsights) ? count($inventoryInsights) : 'Error/Null'));
                }

            } else {
                // Log si GA no está listo
                if ($gaConnection && !$gaConnection->property_id) { Log::error("DashboardService: Usuario user_id: {$user->id} tiene conexión GA4 pero falta property_id."); }
                else { Log::info("DashboardService: Usuario user_id: {$user->id} no tiene conexión GA4."); }
            }

        } catch (Throwable $e) {
            // Captura cualquier excepción de los servicios (API caídas, errores internos)
            Log::error("DashboardService: Error fetching data for user_id: {$user->id}. Error: " . $e->getMessage(), [
                'exception' => $e // Loguear la excepción completa puede ser útil
            ]);
            // Considera devolver un array indicando el error parcial o devolver vacío/null
            // para evitar cachear un resultado incompleto o erróneo permanentemente.
            // Aquí devolvemos un array vacío indicando fallo, y NO lo cacheamos.
             return $this->formatResponse(
                $user,
                $shop,
                $gaConnection,
                $periodLabel,
                $startDate,
                $endDate,
                [], // empty shopify metrics
                [], // empty ga metrics
                [], // empty calculated
                ['error' => 'Failed to fetch partial dashboard data. Please try again later.'] // Indicate error in insights or a top-level key
            );
        }


        // --- 6. Combinar y devolver TODOS los datos ---
         $finalData = $this->formatResponse(
             $user,
             $shop,
             $gaConnection,
             $periodLabel,
             $startDate,
             $endDate,
             $shopifyMetrics,
             $gaMetrics,
             $calculatedMetrics,
             $inventoryInsights
         );

        // --- 7. Guardar en Caché ---
        // Solo cacheamos si NO hubo errores graves (controlado por el try-catch)
        Cache::put($cacheKey, $finalData, self::CACHE_TTL);
        Log::info("DashboardService: Data generated and cached for key: {$cacheKey}");

        return $finalData;
    }

    /**
     * Calcula el rango de fechas basado en el identificador.
     * @param string $periodIdentifier
     * @return array [Carbon $startDate, Carbon $endDate, string $periodLabel]
     */
    private function calculateDateRange(string $periodIdentifier): array
    {
        $endDate = Carbon::now()->endOfDay(); // Por defecto, hoy

        switch ($periodIdentifier) {
            case '30d':
                $startDate = Carbon::now()->subDays(29)->startOfDay();
                $periodLabel = 'last_30_days';
                break;
            case 'this_month':
                $startDate = Carbon::now()->startOfMonth()->startOfDay();
                $periodLabel = 'this_month';
                break;
            case 'last_month':
                $startDate = Carbon::now()->subMonthNoOverflow()->startOfMonth()->startOfDay();
                $endDate = Carbon::now()->subMonthNoOverflow()->endOfMonth()->endOfDay(); // EndDate cambia aquí
                $periodLabel = 'last_month';
                break;
            case '7d':
            default:
                $startDate = Carbon::now()->subDays(6)->startOfDay();
                $periodLabel = 'last_7_days';
                break;
        }
        return [$startDate, $endDate, $periodLabel];
    }

     /**
      * Extrae la lógica de obtención de métricas de Shopify.
      * @param \App\Models\Shop $shop
      * @param Carbon $startDate
      * @param Carbon $endDate
      * @return array|null
      */
     private function fetchShopifyMetrics(\App\Models\Shop $shop, Carbon $startDate, Carbon $endDate): ?array
     {
         Log::info("DashboardService: Obteniendo datos de Shopify para shop_id: {$shop->id}");
         // !!! PUNTO CRÍTICO DE OPTIMIZACIÓN: Revisar ShopifyApiService !!!
         // Asegúrate que estos métodos son eficientes, usan filtros de fecha en la API,
         // y no traen datos innecesarios (ej. órdenes completas si solo necesitas el count/sum).
         $shopInfo = $this->shopifyApiService->getShopInfo($shop); // Suele ser ligero
         $paidSales = $this->shopifyApiService->getTotalSales($shop, $startDate, $endDate); // ¿Agregado en API o localmente?
         $paidOrdersCount = $this->shopifyApiService->getOrdersCount($shop, $startDate, $endDate, 'any', 'paid'); // ¿Agregado en API?
         $totalOrdersCount = $this->shopifyApiService->getOrdersCount($shop, $startDate, $endDate, 'any', null); // ¿Agregado en API?
         $salesTrend = $this->shopifyApiService->getSalesTrend($shop, $startDate, $endDate); // ¿Qué devuelve esto? ¿Datos diarios? ¿Podría ser pesado?

         $aov = null;
         if ($paidSales !== null && $paidOrdersCount !== null) {
             $aov = $paidOrdersCount > 0 ? ($paidSales / $paidOrdersCount) : 0.0;
         }

         $metrics = [
            'shop_name' => $shopInfo['name'] ?? $shop->shop_domain,
            'total_orders_period' => $totalOrdersCount,
            'paid_sales_period' => $paidSales,
            'aov_period' => $aov,
            'sales_trend_period' => $salesTrend, // Cuidado si $salesTrend es un array muy grande
         ];
         Log::debug("DashboardService: Datos Shopify procesados", $metrics);
         return $metrics;
     }

    /**
     * Extrae la lógica de obtención de métricas de GA.
     * @param \App\Models\GaConnection $gaConnection
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array|null
     */
    private function fetchGaMetrics(\App\Models\GaConnection $gaConnection, Carbon $startDate, Carbon $endDate): ?array
    {
        Log::info("DashboardService: Obteniendo datos de GA4 para property: {$gaConnection->property_id}");
        // !!! PUNTO CRÍTICO DE OPTIMIZACIÓN: Revisar GoogleAnalyticsService !!!
        // Asegúrate que las llamadas a la API de GA son eficientes:
        // - Usan los date ranges correctos.
        // - Piden solo las métricas/dimensiones necesarias.
        // - Manejan paginación si es necesario (poco probable para métricas básicas, posible para trafficSources).
        // - No guardan la respuesta *completa* de la API en memoria si solo necesitas partes.
        $basicMetricsResult = $this->googleAnalyticsService->getBasicMetrics($gaConnection, $startDate, $endDate);
        $trafficSources = $this->googleAnalyticsService->getSessionsByChannel($gaConnection, $startDate, $endDate);

        $sessions = null;
        $activeUsers = null;

        if ($basicMetricsResult !== null) {
            $sessions = $basicMetricsResult['sessions'] ?? 0;
            $activeUsers = $basicMetricsResult['activeUsers'] ?? 0;
            Log::info("DashboardService: Métricas básicas GA4 obtenidas", ['sessions' => $sessions, 'activeUsers' => $activeUsers]);
        } else {
            Log::error("DashboardService: Fallo al obtener métricas básicas GA4 para property: {$gaConnection->property_id}", ['result' => $basicMetricsResult]);
        }

        if ($trafficSources === null) {
            Log::error("DashboardService: Fallo al obtener fuentes de tráfico GA4 para property: {$gaConnection->property_id}");
        } else {
             // Cuidado si $trafficSources es un array muy grande
            Log::info("DashboardService: Fuentes de tráfico GA4 obtenidas.", ['count' => count($trafficSources)]);
        }

        $metrics = [
            'sessions_period' => $sessions,
            'active_users_period' => $activeUsers,
            'traffic_sources_period' => $trafficSources, // Potencialmente grande
        ];
        Log::debug("DashboardService: Datos GA4 procesados", $metrics);
        return $metrics;
    }

     /**
      * Calcula métricas combinadas.
      * @param array $shopifyMetrics
      * @param int|null $sessions
      * @return array|null
      */
     private function calculateCombinedMetrics(array $shopifyMetrics, ?int $sessions): ?array
     {
         $conversionRate = null;
         if (isset($shopifyMetrics['total_orders_period']) && $shopifyMetrics['total_orders_period'] !== null && $sessions !== null) {
              $conversionRate = $sessions > 0 ? (($shopifyMetrics['total_orders_period'] / $sessions) * 100) : 0.0;
         }

         $calculated = ['conversion_rate_period' => $conversionRate];
         Log::debug("DashboardService: Métricas calculadas procesadas", $calculated);
         return $calculated;
     }

    /**
     * Formatea la respuesta final del dashboard.
     * @param User $user
     * @param \App\Models\Shop|null $shop
     * @param \App\Models\GaConnection|null $gaConnection
     * @param string $periodLabel
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array|null $shopifyMetrics
     * @param array|null $gaMetrics
     * @param array|null $calculatedMetrics
     * @param array|null $inventoryInsights Can include error key
     * @return array
     */
     private function formatResponse(
         User $user,
         ?\App\Models\Shop $shop,
         ?\App\Models\GaConnection $gaConnection,
         string $periodLabel,
         Carbon $startDate,
         Carbon $endDate,
         ?array $shopifyMetrics,
         ?array $gaMetrics,
         ?array $calculatedMetrics,
         ?array $inventoryInsights
     ): array {
         return [
             'user_name' => $user->name,
             'connections' => [
                 'shopify_connected' => !is_null($shop),
                 'ga4_connected' => !is_null($gaConnection),
                 // Usa optional() o nullsafe operator (?->) para seguridad
                 'ga4_property_set' => !is_null(optional($gaConnection)->property_id),
             ],
             'shopify_metrics' => $shopifyMetrics ?? [],
             'ga_metrics' => $gaMetrics ?? [],
             'calculated_metrics' => $calculatedMetrics ?? [],
             'inventory_insights' => $inventoryInsights ?? [], // Asegúrate que el formato es consistente (array vacío si no hay nada o error)
             'period' => [
                 'label' => $periodLabel,
                 'start_date' => $startDate->toDateString(),
                 'end_date' => $endDate->toDateString(),
             ]
         ];
     }
}