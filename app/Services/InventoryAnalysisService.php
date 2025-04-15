<?php

namespace App\Services;

use App\Models\User;
use App\Services\ShopifyApiService;
use App\Services\GoogleAnalyticsService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache; // <-- Añadir Facade de Cache
use Carbon\Carbon;
use Throwable; // Para capturar excepciones

class InventoryAnalysisService
{
    // --- Constantes para Umbrales ---
    private const LOW_STOCK_THRESHOLD = 10;
    private const HIGH_TRAFFIC_THRESHOLD = 50; // Ajustar según el período de tiempo (ej. 50 vistas en 7 días vs 30 días)
    private const HIGH_STOCK_THRESHOLD = 100;
    private const LOW_TRAFFIC_THRESHOLD = 5;  // Ajustar según el período de tiempo
    private const PRODUCT_LIMIT_FOR_ANALYSIS = 100; // Límite de productos a analizar desde GA

    // Tiempo de caché en segundos (ej: 1 hora) - Ajustar según necesidad
    protected const CACHE_TTL = 3600;

    public function __construct(
        protected ShopifyApiService $shopifyApiService,
        protected GoogleAnalyticsService $googleAnalyticsService
    ) {}

    /**
     * Analiza inventario vs tráfico, usando caché.
     *
     * @param User $user
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array Insights o array vacío. Formato: ['productId', 'productName', 'status', 'stock', 'views', 'message']
     */
    public function analyzeProductInventory(User $user, Carbon $startDate, Carbon $endDate): array
    {
        $periodKey = $startDate->toDateString() . '_' . $endDate->toDateString(); // Clave única para el período
        $cacheKey = "inventory_analysis:user_{$user->id}:period_{$periodKey}";

        // --- a. Intentar obtener de caché ---
        $cachedInsights = Cache::get($cacheKey);
        if ($cachedInsights !== null) { // Usar !== null para permitir caché de array vacío []
            Log::info("InventoryAnalysisService: Devolviendo insights cacheados para user_id: {$user->id}, Periodo: {$periodKey} (Key: {$cacheKey})");
            return $cachedInsights;
        }

        Log::info("InventoryAnalysisService: No cache found for key: {$cacheKey}. Iniciando análisis para user_id: {$user->id}");

        // --- b. Pre-checks ---
        if (!$user->shop || !$user->gaConnection?->property_id) { // Simplificado con optional()
            Log::warning("InventoryAnalysisService: Faltan conexiones (Shopify/GA4/PropertyID) para user_id: {$user->id}. Abortando.");
            // Cacheamos un array vacío para no reintentar constantemente si la conexión falta
            Cache::put($cacheKey, [], self::CACHE_TTL);
            return [];
        }

        try {
            // --- c. Get Product Views (GA4) ---
            Log::debug("InventoryAnalysisService: Obteniendo vistas de producto de GA4...");
            // !!! ASEGURAR QUE getProductViews ESTÁ OPTIMIZADO DENTRO DEL SERVICIO !!!
            $productViewsData = $this->googleAnalyticsService->getProductViews(
                $user->gaConnection,
                $startDate,
                $endDate,
                self::PRODUCT_LIMIT_FOR_ANALYSIS
            );

            if ($productViewsData === null) {
                Log::error("InventoryAnalysisService: Error al obtener vistas de producto de GA4 para user_id: {$user->id}.");
                // No cachear en caso de error de API temporal
                return [];
            }

            if (empty($productViewsData)) {
                Log::warning("InventoryAnalysisService: No se encontraron datos de vistas de producto en GA4 para user_id: {$user->id}.");
                 // Cachear resultado vacío si no hay datos
                Cache::put($cacheKey, [], self::CACHE_TTL);
                return [];
            }
            Log::info("InventoryAnalysisService: ".count($productViewsData)." productos con vistas obtenidos de GA4.");

            // --- d. Extract Product IDs ---
            // Usar array_map para asegurar que sean strings y filtrar nulos/vacíos
            $productIdsToFetch = array_filter(array_map(fn($view) => $view['productId'] ?? null, $productViewsData));

            if (empty($productIdsToFetch)) {
                 Log::warning("InventoryAnalysisService: No se extrajeron IDs de producto válidos de los datos de GA4.");
                  // Cachear resultado vacío
                 Cache::put($cacheKey, [], self::CACHE_TTL);
                 return [];
            }
            Log::debug("InventoryAnalysisService: IDs de producto para buscar inventario:", $productIdsToFetch);

            // --- e. Get Inventory Levels (Shopify) ---
            Log::debug("InventoryAnalysisService: Obteniendo niveles de inventario de Shopify...");
            // !!! ASEGURAR QUE getInventoryLevels ESTÁ ALTAMENTE OPTIMIZADO (GraphQL PREFERIDO) !!!
            $inventoryData = $this->shopifyApiService->getInventoryLevels($user->shop, $productIdsToFetch);

            if ($inventoryData === null) {
                Log::error("InventoryAnalysisService: Error al obtener niveles de inventario de Shopify para user_id: {$user->id}.");
                 // No cachear en caso de error de API temporal
                return [];
            }
            Log::info("InventoryAnalysisService: ".count($inventoryData)." niveles de inventario obtenidos de Shopify.");

            // --- f. Correlate and Analyze ---
            Log::debug("InventoryAnalysisService: Correlacionando datos y aplicando umbrales...");
            $insights = [];
            $processedProductIds = []; // Para evitar duplicados si GA devuelve el mismo ID varias veces

            foreach ($productViewsData as $viewData) {
                $productId = $viewData['productId'] ?? null;

                // Validar y evitar procesar el mismo ID dos veces si GA lo incluyera
                if (!$productId || isset($processedProductIds[$productId])) {
                    continue;
                }

                $productName = $viewData['productName'] ?? 'Nombre Desconocido';
                $views = (int) ($viewData['views'] ?? 0); // Asegurar que es entero

                // Buscar inventario. Si no existe la clave, asumimos stock 0 o desconocido (null).
                // Es importante que getInventoryLevels devuelva 0 si un producto existe pero no tiene stock.
                $currentStock = $inventoryData[$productId] ?? null; // Usar el ID directamente como clave

                 // Si no obtuvimos stock (quizás el producto ya no existe en Shopify pero sí en GA)
                 if ($currentStock === null) {
                      Log::debug("InventoryAnalysisService: No se encontró inventario para productId {$productId}. Saltando.");
                      $processedProductIds[$productId] = true; // Marcar como procesado
                      continue;
                 }
                 $currentStock = (int) $currentStock; // Asegurar que es entero

                // --- Aplicar Lógica / Umbrales ---
                $insight = null;
                if ($currentStock <= self::LOW_STOCK_THRESHOLD && $views >= self::HIGH_TRAFFIC_THRESHOLD) {
                    $insight = $this->createInsight(
                        $productId, $productName, 'stockout_risk', $currentStock, $views,
                        "Stock bajo ({$currentStock}) con alto interés ({$views} vistas)."
                    );
                } elseif ($currentStock >= self::HIGH_STOCK_THRESHOLD && $views <= self::LOW_TRAFFIC_THRESHOLD) {
                     $insight = $this->createInsight(
                        $productId, $productName, 'promotion_candidate', $currentStock, $views,
                        "Alto stock ({$currentStock}) con bajo interés ({$views} vistas)."
                    );
                }
                // Añadir más 'elseif' para otras condiciones si es necesario

                if ($insight) {
                    $insights[] = $insight;
                    Log::debug("Insight agregado: {$insight['status']} para producto {$productId}");
                }

                $processedProductIds[$productId] = true; // Marcar como procesado
            }

            Log::info("InventoryAnalysisService: Análisis completado para user_id: {$user->id}. Insights generados: " . count($insights));

             // --- g. Guardar en Caché y Devolver ---
            Cache::put($cacheKey, $insights, self::CACHE_TTL);
            Log::info("InventoryAnalysisService: Insights generados y cacheados para key: {$cacheKey}");
            return $insights;

        } catch (Throwable $e) {
            // Capturar cualquier excepción inesperada durante el proceso
            Log::error("InventoryAnalysisService: Excepción durante el análisis para user_id: {$user->id}. Error: " . $e->getMessage(), [
                'exception' => $e
            ]);
            // No cachear en caso de excepción
            return [['error' => 'Analysis failed due to an unexpected error.']]; // Devolver indicativo de error
        }
    }

     /**
      * Helper para crear la estructura del insight.
      */
     private function createInsight(string $productId, string $productName, string $status, int $stock, int $views, string $message): array
     {
        return [
            'productId' => $productId,
            'productName' => $productName,
            'status' => $status,
            'stock' => $stock,
            'views' => $views,
            'message' => $message
        ];
     }
}