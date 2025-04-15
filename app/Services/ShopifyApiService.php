<?php

namespace App\Services;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod; // Importar CarbonPeriod para iterar fechas

class ShopifyApiService
{
    protected string $apiVersion = '2024-04'; // Usa una versión de API válida

    // --- getShopInfo (sin cambios) ---
    public function getShopInfo(Shop $shop): ?array
    {
        if (!$shop->access_token || !$shop->shop_domain) {
            Log::warning("Intento de obtener info de Shopify sin token o dominio para user_id: {$shop->user_id}");
            return null;
        }
        $apiUrl = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/shop.json";
        try {
            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $shop->access_token,
                'Accept' => 'application/json',
            ])->get($apiUrl);
            if ($response->successful()) {
                return $response->json('shop');
            } else {
                Log::error("Error al obtener shop info para {$shop->shop_domain}. Status: " . $response->status(), [
                    'response_body' => $response->body()
                ]);
                return null;
            }
        } catch (\Exception $e) {
            Log::error("Excepción al obtener shop info para {$shop->shop_domain}: " . $e->getMessage());
            return null;
        }
    }

    // --- getOrdersCount (MODIFICADO) ---
    /**
     * Obtiene el número de pedidos para un rango de fechas y filtros opcionales.
     *
     * @param Shop $shop El modelo de la tienda.
     * @param Carbon $startDate Fecha de inicio del período.
     * @param Carbon $endDate Fecha de fin del período.
     * @param string $status Estado general del pedido (ej. 'any', 'open', 'closed', 'cancelled'). Por defecto 'any'.
     * @param string|null $financialStatus Estado financiero (ej. 'paid', 'pending', 'refunded'). Null para no filtrar.
     * @return int|null El número de pedidos o null en caso de error.
     */
    public function getOrdersCount(Shop $shop, Carbon $startDate, Carbon $endDate, string $status = 'any', ?string $financialStatus = null): ?int
    {
        if (!$shop->access_token || !$shop->shop_domain) {
            Log::warning("Intento de obtener order count sin token o dominio para user_id: {$shop->user_id}");
            return null;
        }

        // Construimos los queryParams dentro del método
        $queryParams = [
            'status' => $status,
            'created_at_min' => $startDate->toIso8601String(),
            'created_at_max' => $endDate->toIso8601String(),
        ];

        // Añadir financial_status solo si se proporciona
        if ($financialStatus !== null) {
            $queryParams['financial_status'] = $financialStatus;
        }

        $apiUrl = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/orders/count.json";

        try {
            Log::info("Obteniendo count de pedidos para {$shop->shop_domain}", $queryParams); // Log para ver los params

            $response = Http::withHeaders([
                'X-Shopify-Access-Token' => $shop->access_token,
                'Accept' => 'application/json',
            ])->retry(3, 1000) // Añadido reintento como en otros métodos
              ->get($apiUrl, $queryParams);

            if ($response->successful()) {
                $count = $response->json('count');
                Log::info("Count de pedidos obtenido para {$shop->shop_domain}: {$count}");
                return is_numeric($count) ? (int)$count : null;
            } else {
                 Log::error("Error al obtener orders count para {$shop->shop_domain}. Status: " . $response->status(), [
                    'query_params' => $queryParams,
                    'response_body' => $response->body()
                ]);
                 // Manejar rate limits como en getTotalSales
                if ($response->status() === 429) {
                     sleep(5); // Espera simple, podría mejorarse
                     // Podríamos reintentar aquí o devolver un código específico/null
                     return null; // Devolver null por ahora en rate limit tras reintentos
                }
                return null;
            }
        } catch (\Exception $e) {
             Log::error("Excepción al obtener orders count para {$shop->shop_domain}: " . $e->getMessage(), ['params' => $queryParams]);
            return null;
        }
    }


    // --- getTotalSales (sin cambios respecto al código que proporcionaste) ---
    public function getTotalSales(Shop $shop, Carbon $startDate, Carbon $endDate): ?float
    {
         if (!$shop->access_token || !$shop->shop_domain) {
            Log::warning("Intento de obtener total sales sin token o dominio para user_id: {$shop->user_id}");
            return null;
        }
        $totalSales = 0.0; $nextPageUrl = null;
        $queryParams = ['status'=>'any','financial_status'=>'paid','created_at_min'=>$startDate->toIso8601String(),'created_at_max'=>$endDate->toIso8601String(),'limit'=>250,'fields'=>'total_price',];
        $apiUrl = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/orders.json";
        Log::info("Iniciando fetch de ventas para {$shop->shop_domain} desde {$startDate->toIso8601String()} hasta {$endDate->toIso8601String()}");
        do { try { $currentUrl=$nextPageUrl?:$apiUrl;$currentParams=$nextPageUrl?[]:$queryParams;$response=Http::withHeaders(['X-Shopify-Access-Token'=>$shop->access_token,'Accept'=>'application/json',])->retry(3,1000)->get($currentUrl,$currentParams);if($response->failed()){Log::error("Error al obtener página de pedidos para {$shop->shop_domain}. Status: ".$response->status(),['url'=>$currentUrl,'params'=>$currentParams,'response_body'=>$response->body()]);if($response->status()===429){sleep(5);continue;}return null;}$orders=$response->json('orders');if(is_array($orders)){foreach($orders as $order){if(isset($order['total_price'])&&is_numeric($order['total_price'])){$totalSales+=(float)$order['total_price'];}}}$nextPageUrl=null;$linkHeader=$response->header('Link');if($linkHeader){$links=explode(',',$linkHeader);foreach($links as $link){if(strpos($link,'rel="next"')!==false){preg_match('/<(.*?)>/',$link,$matches);if(isset($matches[1])){$nextPageUrl=$matches[1];Log::info("Paginación: Encontrada siguiente página para {$shop->shop_domain}");break;}}}}}catch(\Exception $e){Log::error("Excepción al obtener página de pedidos para {$shop->shop_domain}: ".$e->getMessage(),['url'=>$nextPageUrl?:$apiUrl]);return null;}}while($nextPageUrl);Log::info("Fetch de ventas completado para {$shop->shop_domain}. Total: ".$totalSales);return $totalSales;
    }

    // --- MÉTODO getSalesTrend (sin cambios respecto al código que proporcionaste) ---
    public function getSalesTrend(Shop $shop, Carbon $startDate, Carbon $endDate): ?array
    {
        if (!$shop->access_token || !$shop->shop_domain) {
            Log::warning("Intento de obtener sales trend sin token o dominio para user_id: {$shop->user_id}");
            return null;
        }

        // 1. Inicializar array de ventas diarias con 0 para cada día del rango
        $dailySales = [];
        $period = CarbonPeriod::create($startDate->startOfDay(), $endDate->endOfDay()); // Asegura cubrir días completos
        foreach ($period as $date) {
            $dailySales[$date->toDateString()] = ['date' => $date->toDateString(), 'sales' => 0.0];
        }

        // 2. Preparar la llamada API
        $nextPageUrl = null;
        $queryParams = [
            'status' => 'any',
            'financial_status' => 'paid',
            'created_at_min' => $startDate->toIso8601String(),
            'created_at_max' => $endDate->toIso8601String(),
            'limit' => 250,
            'fields' => 'created_at,total_price', // Necesitamos created_at para agrupar
        ];
        $apiUrl = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/orders.json";

        Log::info("Iniciando fetch de trend de ventas para {$shop->shop_domain} desde {$startDate->toIso8601String()} hasta {$endDate->toIso8601String()}");

        // 3. Bucle de paginación para obtener pedidos
        do {
            try {
                $currentUrl = $nextPageUrl ?: $apiUrl;
                $currentParams = $nextPageUrl ? [] : $queryParams;

                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $shop->access_token,
                    'Accept' => 'application/json',
                ])->retry(3, 1000)->get($currentUrl, $currentParams);

                if ($response->failed()) {
                     Log::error("Error al obtener página de pedidos (trend) para {$shop->shop_domain}. Status: " . $response->status(), [
                        'url'=>$currentUrl,'params'=>$currentParams,'response_body'=>$response->body()]);
                     if ($response->status() === 429) { sleep(5); continue; }
                    return null;
                }

                $orders = $response->json('orders');
                if (is_array($orders)) {
                    foreach ($orders as $order) {
                         if (isset($order['created_at']) && isset($order['total_price']) && is_numeric($order['total_price'])) {
                            // Obtener fecha en formato YYYY-MM-DD
                            $orderDate = Carbon::parse($order['created_at'])->toDateString();
                            // Sumar al día correspondiente si está dentro del rango que inicializamos
                            if (isset($dailySales[$orderDate])) {
                                $dailySales[$orderDate]['sales'] += (float)$order['total_price'];
                            }
                        }
                    }
                }

                // --- Manejo de Paginación ---
                $nextPageUrl = null;
                $linkHeader = $response->header('Link');
                if ($linkHeader) {
                    $links = explode(',', $linkHeader);
                    foreach ($links as $link) {
                        if (strpos($link, 'rel="next"') !== false) {
                            preg_match('/<(.*?)>/', $link, $matches);
                            if (isset($matches[1])) { $nextPageUrl = $matches[1]; break; }
                        }
                    }
                }
                // --------------------------

            } catch (\Exception $e) {
                 Log::error("Excepción al obtener página de pedidos (trend) para {$shop->shop_domain}: " . $e->getMessage(), ['url' => $nextPageUrl ?: $apiUrl]);
                return null;
            }

        } while ($nextPageUrl);

         Log::info("Fetch de trend de ventas completado para {$shop->shop_domain}.");
        // Devuelve solo los valores del array (los objetos ['date'=>..., 'sales'=>...])
        return array_values($dailySales);
    }

    public function getInventoryLevels(Shop $shop, array $productIds): ?array
    {
        if (!$shop->access_token || !$shop->shop_domain) {
            Log::warning("Intento de obtener inventario sin token o dominio para user_id: {$shop->user_id}");
            return null;
        }
        if (empty($productIds)) {
            return []; // No hay IDs para buscar
        }

        // Asegurarse de que los IDs son numéricos (por seguridad)
        $numericProductIds = array_filter($productIds, 'is_numeric');
        if (empty($numericProductIds)) {
             Log::warning("getInventoryLevels: No se proporcionaron IDs de producto numéricos válidos.", ['original_ids' => $productIds]);
             return [];
        }

        $inventoryData = [];
        $apiUrlBase = "https://{$shop->shop_domain}/admin/api/{$this->apiVersion}/products.json";

        // El endpoint products.json tiene un límite de 250 IDs por llamada
        $idChunks = array_chunk($numericProductIds, 250);

        Log::info("Iniciando fetch de inventario para {$shop->shop_domain}", ['total_products' => count($numericProductIds), 'chunks' => count($idChunks)]);

        foreach ($idChunks as $chunk) {
            $queryParams = [
                'ids' => implode(',', $chunk),
                'fields' => 'id,title,variants', // Pedimos id, título (útil para logs/debug) y variantes
                'limit' => 250 // Aunque filtramos por IDs, es bueno incluir el límite
            ];

            try {
                $response = Http::withHeaders([
                    'X-Shopify-Access-Token' => $shop->access_token,
                    'Accept' => 'application/json',
                ])->retry(3, 1000)->get($apiUrlBase, $queryParams);

                if ($response->failed()) {
                    Log::error("Error al obtener chunk de productos para inventario {$shop->shop_domain}. Status: " . $response->status(), [
                        'query_params' => $queryParams, // Log query params para debug
                        'response_body' => $response->body()
                    ]);
                    if ($response->status() === 429) {
                         sleep(5);
                         // Podríamos reintentar el chunk o fallar todo. Fallar por ahora.
                         Log::error("Rate limit alcanzado al obtener inventario. Abortando.");
                         return null;
                    }
                    // Si falla por otra razón, continuamos al siguiente chunk? O fallamos todo? Fallar es más seguro.
                     return null;
                }

                $products = $response->json('products');

                if (is_array($products)) {
                    foreach ($products as $product) {
                        $productId = Arr::get($product, 'id');
                        if (!$productId) continue; // Saltar si no hay ID

                        $totalInventory = 0;
                        $variants = Arr::get($product, 'variants', []);

                        if (is_array($variants)) {
                            foreach ($variants as $variant) {
                                // Sumar solo si inventory_quantity es numérico
                                $qty = Arr::get($variant, 'inventory_quantity');
                                if (is_numeric($qty)) {
                                    $totalInventory += (int)$qty;
                                }
                            }
                        }
                        // Guardar el total por ID de producto
                        $inventoryData[$productId] = $totalInventory;
                         Log::debug("Inventario calculado para producto {$productId}: {$totalInventory}");
                    }
                } else {
                     Log::warning("La respuesta de productos no fue un array válido para el chunk.", ['chunk_ids' => $chunk]);
                }

            } catch (\Exception $e) {
                 Log::error("Excepción al obtener chunk de productos para inventario {$shop->shop_domain}: " . $e->getMessage(), [
                    'chunk_ids' => $chunk,
                    'exception' => $e
                 ]);
                 return null; // Fallar todo si hay una excepción
            }
        } // Fin foreach chunk

        Log::info("Fetch de inventario completado para {$shop->shop_domain}. Productos encontrados: " . count($inventoryData));

        // Devolvemos el array asociativo [productId => inventorySum]
        return $inventoryData;
    }
}