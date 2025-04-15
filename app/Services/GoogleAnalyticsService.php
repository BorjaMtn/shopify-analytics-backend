<?php

namespace App\Services;

use App\Models\GaConnection;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Google\Client as GoogleClient;
use Google\Service\AnalyticsData as GoogleAnalyticsData;
use Google\Service\AnalyticsData\DateRange;
use Google\Service\AnalyticsData\Dimension;
use Google\Service\AnalyticsData\Metric;
use Google\Service\AnalyticsData\OrderBy; // Asegúrate de importar OrderBy
use Google\Service\AnalyticsData\MetricOrderBy; // Asegúrate de importar MetricOrderBy
use Google\Service\AnalyticsData\RunReportRequest;
use Google\Service\AnalyticsData\BatchRunReportsRequest; // <-- Para optimización Batch
use Google\Service\AnalyticsData\BatchRunReportsResponse; // <-- Para optimización Batch
use Google\Service\Exception as GoogleServiceException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\Google as GoogleOAuthProvider;
use Throwable; // Usar Throwable para capturar más tipos de errores

class GoogleAnalyticsService
{
    protected GoogleClient $googleClient;
    protected GoogleOAuthProvider $googleOAuthProvider;
    protected bool $tokenRefreshedInRequest = false;

    /**
     * Constructor optimizado.
     * Asume que GoogleClient y GoogleOAuthProvider se configuran/inyectan
     * correctamente a través del Service Container de Laravel.
     * Si no es así, necesitarías instanciarlos aquí con la configuración.
     */
    public function __construct(GoogleClient $googleClient, GoogleOAuthProvider $googleOAuthProvider)
    {
        $this->googleClient = $googleClient;
        $this->googleOAuthProvider = $googleOAuthProvider;

        // Configurar redirección y credenciales si no se hace vía Service Provider
        // $this->googleClient->setClientId(config('services.google.client_id'));
        // $this->googleClient->setClientSecret(config('services.google.client_secret'));
        // $this->googleClient->setRedirectUri(config('services.google.redirect_uri'));
        // $this->googleClient->setAccessType('offline');
        // $this->googleClient->setApprovalPrompt('force'); // Para asegurar refresh token
        // $this->googleClient->addScope([/* ...scopes necesarios... */]);

        // $this->googleOAuthProvider = new GoogleOAuthProvider([
        //     'clientId'     => config('services.google.client_id'),
        //     'clientSecret' => config('services.google.client_secret'),
        //     'redirectUri'  => config('services.google.redirect_uri'),
        // ]);
    }

    /**
     * Intenta refrescar el token de acceso.
     * (Sin cambios, la lógica original es buena)
     * @param GaConnection $connection
     * @return string|null Nuevo access token o null si falla.
     */
    protected function attemptTokenRefresh(GaConnection $connection): ?string
    {
        $userId = $connection->user_id;
        Log::info("[User:{$userId}] attemptTokenRefresh: Intentando refrescar token.");

        $encryptedRefreshToken = $connection->getRawOriginal('refresh_token');
        if (!$encryptedRefreshToken) {
            Log::error("[User:{$userId}] attemptTokenRefresh: No hay refresh token en BD.");
            return null;
        }

        try {
            $decryptedRefreshToken = Crypt::decryptString($encryptedRefreshToken);
        } catch (DecryptException $e) {
            Log::error("[User:{$userId}] attemptTokenRefresh: No se pudo desencriptar refresh token.", ['message' => $e->getMessage()]);
            return null;
        }

        try {
            $newToken = $this->googleOAuthProvider->getAccessToken('refresh_token', [
                'refresh_token' => $decryptedRefreshToken
            ]);
            Log::info("[User:{$userId}] attemptTokenRefresh: ¡Nuevo token recibido de Google vía refresh!");

            $newAccessToken = $newToken->getToken();
            $newExpiresIn = $newToken->getExpires();
            $newExpiresAt = $newExpiresIn ? Carbon::now()->addSeconds($newExpiresIn) : null;
            $returnedRefreshToken = $newToken->getRefreshToken(); // Google puede devolver uno nuevo
            $newRefreshTokenToStore = $returnedRefreshToken ?? $decryptedRefreshToken;

            // Usar Mass Assignment requiere que los campos estén en $fillable del modelo GaConnection
            $connection->update([
                'access_token' => $newAccessToken,
                'expires_at' => $newExpiresAt,
                'refresh_token' => $newRefreshTokenToStore, // Guardar el desencriptado o el nuevo si Google lo da
            ]);
            Log::info("[User:{$userId}] attemptTokenRefresh: Token GA4 refrescado y actualizado en BD.");
            $this->tokenRefreshedInRequest = true;
            return $newAccessToken;

        } catch (IdentityProviderException $e) {
            Log::error("[User:{$userId}] attemptTokenRefresh: IdentityProviderException ¡REFRESH TOKEN INVÁLIDO/REVOCADO!: " . $e->getMessage(), ['response_body' => $e->getResponseBody()]);
            // Considerar marcar la conexión como inválida en la BD aquí
            // $connection->update(['status' => 'invalid_token']);
            return null;
        } catch (Throwable $e) { // Capturar Throwable para más robustez
            Log::error("[User:{$userId}] attemptTokenRefresh: Excepción general al intentar refrescar: " . $e->getMessage(), ['exception_class' => get_class($e), 'trace' => $e->getTraceAsString()]);
            return null;
        }
    }

    /**
     * Obtiene un token de acceso válido.
     * (Sin cambios, la lógica original es buena)
     * @param GaConnection $connection
     * @return string|null
     */
    protected function getValidAccessToken(GaConnection $connection): ?string
    {
        $userId = $connection->user_id;
        // Obtener el token desencriptado usando el accesor/mutator si existe
        $currentAccessToken = $connection->access_token;
        $expiresAt = $connection->expires_at; // Asume que es un objeto Carbon si está casteado en el modelo

        // Validar si expira en más de 1 minuto en el futuro
        $isFuture = $expiresAt instanceof Carbon && $expiresAt->isFuture() && $expiresAt->gt(Carbon::now()->addMinutes(1));

        Log::debug("[User:{$userId}] getValidAccessToken: Chequeando validez. Token existe=" . (!empty($currentAccessToken)) . ", Expira en futuro=" . (int)$isFuture);

        if ($currentAccessToken && $isFuture) {
            Log::debug("[User:{$userId}] getValidAccessToken: Usando token actual (parece válido).");
            return $currentAccessToken;
        }

        Log::info("[User:{$userId}] getValidAccessToken: Token actual no válido o expirado. Intentando refrescar...");
        // Si el token existe pero expiró, intentar refrescar
        if ($connection->refresh_token) {
             return $this->attemptTokenRefresh($connection);
        } else {
            Log::error("[User:{$userId}] getValidAccessToken: Token expirado/inválido y NO hay refresh token disponible.");
            return null;
        }
    }

    /**
     * Ejecuta UNA petición a la API de GA4 Data (runReport) con manejo de error 401 y reintento.
     * Instancia el servicio una vez.
     *
     * @param GaConnection $connection
     * @param string $ga4PropertyId
     * @param RunReportRequest $requestBody
     * @param string $reportType Etiqueta para logging
     * @return GoogleAnalyticsData\RunReportResponse|null
     */
    protected function runReportWithErrorHandling(GaConnection $connection, string $ga4PropertyId, RunReportRequest $requestBody, string $reportType = 'report'): ?GoogleAnalyticsData\RunReportResponse
    {
        $userId = $connection->user_id;
        $this->tokenRefreshedInRequest = false; // Resetear flag

        $accessToken = $this->getValidAccessToken($connection);
        if (!$accessToken) {
            Log::error("runReportWithErrorHandling ({$reportType}): No se pudo obtener token válido inicial para user {$userId}");
            return null;
        }

        // Instanciar el servicio de Google una sola vez
        try {
             $this->googleClient->setAccessToken($accessToken);
             $analyticsData = new GoogleAnalyticsData($this->googleClient);
        } catch (Throwable $e) {
             Log::error("runReportWithErrorHandling ({$reportType}): Error instanciando GoogleAnalyticsData o seteando token para user {$userId}: " . $e->getMessage());
             return null;
        }

        try {
            // --- Intento 1 ---
            Log::info("Ejecutando runReport ({$reportType}) - Intento 1 para GA4 user {$userId}, propiedad {$ga4PropertyId}");
            $response = $analyticsData->properties->runReport($ga4PropertyId, $requestBody); // Añadir 'properties/'
            Log::info("Respuesta de GA4 Data API ({$reportType}) - Intento 1 OK para user {$userId}");
            return $response;

        } catch (GoogleServiceException $e) {
            // --- Error en Intento 1 ---
            if ($e->getCode() == 401 && !$this->tokenRefreshedInRequest) {
                Log::warning("runReportWithErrorHandling ({$reportType}): Error 401 en Intento 1. Intentando forzar refresh y reintentar. User: {$userId}", ['google_error' => $e->getMessage()]);

                $newAccessToken = $this->attemptTokenRefresh($connection); // attemptTokenRefresh ya setea tokenRefreshedInRequest a true si tiene éxito

                if ($newAccessToken) {
                    try {
                        // --- Intento 2 (Post-Refresh) ---
                        Log::info("Ejecutando runReport ({$reportType}) - Intento 2 (Post-Refresh) para GA4 user {$userId}, propiedad {$ga4PropertyId}");
                        $this->googleClient->setAccessToken($newAccessToken); // Usar el nuevo token
                        // Re-instanciar NO suele ser necesario con google-api-php-client, setAccessToken debería bastar.
                        // $analyticsData = new GoogleAnalyticsData($this->googleClient);
                        $response = $analyticsData->properties->runReport($ga4PropertyId, $requestBody);
                        Log::info("Respuesta de GA4 Data API ({$reportType}) - Intento 2 OK para user {$userId}");
                        return $response;

                    } catch (GoogleServiceException $e2) {
                        Log::error("runReportWithErrorHandling ({$reportType}): Error en Intento 2 (Post-Refresh) para user {$userId}: " . $e2->getMessage(), ['google_errors' => $e2->getErrors()]);
                        return null; // Falló el segundo intento
                    } catch (Throwable $e2) { // Capturar Throwable
                        Log::error("runReportWithErrorHandling ({$reportType}): Excepción general en Intento 2 para user {$userId}: " . $e2->getMessage(), ['exception_class' => get_class($e2)]);
                        return null;
                    }
                } else {
                    Log::error("runReportWithErrorHandling ({$reportType}): Error 401 inicial Y falló el intento de refresh para user {$userId}.");
                    return null; // Falló el refresh
                }
            } else {
                // Error no es 401, o ya se reintentó (tokenRefreshedInRequest es true)
                Log::error("runReportWithErrorHandling ({$reportType}): Error API GA (No 401, o ya reintentado, o error diferente) para user {$userId}: " . $e->getMessage(), [
                    'code' => $e->getCode(),
                    'google_errors' => $e->getErrors() // Muy útil para debug
                ]);
                return null;
            }
        } catch (Throwable $e) { // Capturar Throwable
            Log::error("runReportWithErrorHandling ({$reportType}): Excepción general para user {$userId}: " . $e->getMessage(), ['exception_class' => get_class($e)]);
            return null;
        }
    }

    // --- Métodos Públicos (Lógica interna sin cambios, solo usan el helper optimizado) ---

    /**
     * Obtiene métricas básicas (sesiones, usuarios activos).
     *
     * @param GaConnection $connection
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array|null ['sessions' => int, 'activeUsers' => int] o null en error
     */
    public function getBasicMetrics(GaConnection $connection, Carbon $startDate, Carbon $endDate): ?array
    {
         $userId = $connection->user_id;
         Log::info("getBasicMetrics: Iniciado para user {$userId}");
         $ga4PropertyId = $connection->property_id;
         if (!$ga4PropertyId) { Log::error("getBasicMetrics: Falta property_id para user {$userId}."); return null; }

         $requestBody = new RunReportRequest([
              'dateRanges' => [new DateRange(['startDate' => $startDate->format('Y-m-d'), 'endDate' => $endDate->format('Y-m-d')]) ],
              'metrics' => [ new Metric(['name' => 'sessions']), new Metric(['name' => 'activeUsers']) ]
         ]);

         $response = $this->runReportWithErrorHandling($connection, $ga4PropertyId, $requestBody, 'basic');

         if ($response && $response->getRowCount() > 0) {
             $metrics = ['sessions' => 0, 'activeUsers' => 0];
             $row = $response->getRows()[0];
             $metricValues = $row->getMetricValues();
             // Acceso más seguro a los valores por índice
             $metrics['sessions'] = isset($metricValues[0]) ? (int)$metricValues[0]->getValue() : 0;
             $metrics['activeUsers'] = isset($metricValues[1]) ? (int)$metricValues[1]->getValue() : 0;
             Log::info("Métricas GA4 básicas extraídas para user {$userId}:", $metrics);
             return $metrics;
         } elseif ($response) {
             Log::info("Informe GA4 (basic) no devolvió filas para user {$userId}");
             return ['sessions' => 0, 'activeUsers' => 0];
         } else {
             // Error ya logueado por runReportWithErrorHandling
             return null;
         }
    }

    /**
     * Obtiene sesiones por canal.
     *
     * @param GaConnection $connection
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @return array|null Lista de ['channel' => string, 'sessions' => int] o null en error
     */
    public function getSessionsByChannel(GaConnection $connection, Carbon $startDate, Carbon $endDate): ?array
    {
        $userId = $connection->user_id;
        Log::info("getSessionsByChannel: Iniciado para user {$userId}");
        $ga4PropertyId = $connection->property_id;
        if (!$ga4PropertyId) { Log::error("getSessionsByChannel: Falta property_id para user {$userId}."); return null; }

        $requestBody = new RunReportRequest([
             'dateRanges' => [ new DateRange(['startDate' => $startDate->format('Y-m-d'), 'endDate' => $endDate->format('Y-m-d')]) ],
             'metrics' => [ new Metric(['name' => 'sessions']) ],
             'dimensions' => [ new Dimension(['name' => 'sessionDefaultChannelGroup']) ],
             'orderBys' => [ new OrderBy([ 'metric' => new MetricOrderBy(['metricName' => 'sessions']), 'desc' => true ])] // Ordenar por sesiones desc
        ]);

        $response = $this->runReportWithErrorHandling($connection, $ga4PropertyId, $requestBody, 'channels');

        $channelData = [];
        if ($response && $response->getRowCount() > 0) {
            foreach ($response->getRows() as $row) {
                $dimensionValues = $row->getDimensionValues();
                $metricValues = $row->getMetricValues();
                 // Acceso más seguro
                if (isset($dimensionValues[0], $metricValues[0])) {
                    $channelData[] = [
                         'channel' => $dimensionValues[0]->getValue() ?? 'unknown', // Valor por defecto
                         'sessions' => (int) ($metricValues[0]->getValue() ?? 0) // Valor por defecto y casteo
                    ];
                }
            }
            Log::info("Datos de canales GA4 extraídos para user {$userId}.", ['count' => count($channelData)]);
            // Podríamos ordenar aquí si GA no lo hiciera, pero ya pedimos orden en la API
            // usort($channelData, fn($a, $b) => $b['sessions'] <=> $a['sessions']);
            return $channelData;
        } elseif ($response) {
            Log::info("Informe GA4 (channels) no devolvió filas para user {$userId}");
            return [];
        } else {
            return null;
        }
    }

    /**
     * Obtiene las vistas de producto (basado en evento view_item).
     *
     * @param GaConnection $connection
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param int $limit Número máximo de productos a devolver.
     * @return array|null Lista de ['productId', 'productName', 'views'] o null en error.
     */
     public function getProductViews(GaConnection $connection, Carbon $startDate, Carbon $endDate, int $limit = 50): ?array
     {
         $userId = $connection->user_id;
         Log::info("getProductViews: Iniciado para user {$userId} con limite {$limit}");
         $ga4PropertyId = $connection->property_id;
         if (!$ga4PropertyId) { Log::error("getProductViews: Falta property_id para user {$userId}."); return null; }

         // NOTA: La métrica es 'itemsViewed', dimensiones 'itemId', 'itemName'
         // Asegúrate que el evento 'view_item' en GA4 esté configurado para enviar estos parámetros.
         $requestBody = new RunReportRequest([
              'dateRanges' => [ new DateRange(['startDate' => $startDate->format('Y-m-d'), 'endDate' => $endDate->format('Y-m-d')]) ],
              'metrics' => [ new Metric(['name' => 'itemsViewed']) ], // Métrica correcta para vistas de item
              'dimensions' => [ new Dimension(['name' => 'itemId']), new Dimension(['name' => 'itemName']) ], // IDs y Nombres
              'orderBys' => [ new OrderBy([ 'metric' => new MetricOrderBy(['metricName' => 'itemsViewed']), 'desc' => true ]) ], // Ordenar por vistas
              'limit' => $limit // Aplicar el límite
         ]);

         $response = $this->runReportWithErrorHandling($connection, $ga4PropertyId, $requestBody, 'product_views');

         $productViewData = [];
         if ($response && $response->getRowCount() > 0) {
             foreach ($response->getRows() as $row) {
                 $dimensionValues = $row->getDimensionValues();
                 $metricValues = $row->getMetricValues();
                 // Acceso más seguro y validación
                 if (isset($dimensionValues[0], $dimensionValues[1], $metricValues[0]) &&
                     !empty($dimensionValues[0]->getValue())) // Asegurar que el ID no esté vacío
                 {
                     $productViewData[] = [
                         'productId' => $dimensionValues[0]->getValue(), // itemId
                         'productName' => $dimensionValues[1]->getValue() ?? 'Unknown Name', // itemName
                         'views' => (int) ($metricValues[0]->getValue() ?? 0) // itemsViewed
                     ];
                 }
             }
             Log::info("Datos de vistas de producto GA4 extraídos para user {$userId}.", ['count' => count($productViewData)]);
             return $productViewData;
         } elseif ($response) {
              Log::warning("Informe GA4 (product views) no devolvió filas para user {$userId}. ¿Tracking 'view_item' con itemId/itemName activo?");
              return [];
         } else {
              return null;
         }
     }

    // --- OPTIMIZACIÓN POTENCIAL: Método para ejecutar múltiples reportes en una llamada ---
    /**
     * [Experimental] Ejecuta múltiples reportes en una sola llamada batch.
     *
     * @param GaConnection $connection
     * @param string $ga4PropertyId
     * @param RunReportRequest[] $reportRequests Array de objetos RunReportRequest
     * @return BatchRunReportsResponse|null
     */
    protected function batchRunReportsWithErrorHandling(GaConnection $connection, string $ga4PropertyId, array $reportRequests): ?BatchRunReportsResponse
    {
        $userId = $connection->user_id;
        $this->tokenRefreshedInRequest = false; // Resetear flag

        $accessToken = $this->getValidAccessToken($connection);
        if (!$accessToken) {
            Log::error("batchRunReportsWithErrorHandling: No se pudo obtener token válido inicial para user {$userId}");
            return null;
        }

        try {
            $this->googleClient->setAccessToken($accessToken);
            $analyticsData = new GoogleAnalyticsData($this->googleClient);
        } catch (Throwable $e) {
            Log::error("batchRunReportsWithErrorHandling: Error instanciando GoogleAnalyticsData o seteando token para user {$userId}: " . $e->getMessage());
            return null;
        }

        $batchRequest = new BatchRunReportsRequest(['requests' => $reportRequests]);

        try {
            // --- Intento 1 ---
            Log::info("Ejecutando batchRunReports - Intento 1 para GA4 user {$userId}, propiedad {$ga4PropertyId}. Informes: " . count($reportRequests));
            $response = $analyticsData->properties->batchRunReports('properties/' . $ga4PropertyId, $batchRequest);
            Log::info("Respuesta de GA4 Data API (batch) - Intento 1 OK para user {$userId}");
            return $response;

        } catch (GoogleServiceException $e) {
             // --- Error en Intento 1 ---
             if ($e->getCode() == 401 && !$this->tokenRefreshedInRequest) {
                 Log::warning("batchRunReportsWithErrorHandling: Error 401 en Intento 1. Intentando forzar refresh y reintentar. User: {$userId}", ['google_error' => $e->getMessage()]);
                 $newAccessToken = $this->attemptTokenRefresh($connection);

                 if ($newAccessToken) {
                     try {
                        // --- Intento 2 ---
                        Log::info("Ejecutando batchRunReports - Intento 2 (Post-Refresh) para GA4 user {$userId}");
                        $this->googleClient->setAccessToken($newAccessToken);
                        $response = $analyticsData->properties->batchRunReports('properties/' . $ga4PropertyId, $batchRequest);
                        Log::info("Respuesta de GA4 Data API (batch) - Intento 2 OK para user {$userId}");
                        return $response;
                     } catch (GoogleServiceException $e2) {
                         Log::error("batchRunReportsWithErrorHandling: Error en Intento 2 (Post-Refresh) para user {$userId}: " . $e2->getMessage(), ['google_errors' => $e2->getErrors()]);
                         return null;
                     } catch (Throwable $e2) {
                         Log::error("batchRunReportsWithErrorHandling: Excepción general en Intento 2 para user {$userId}: " . $e2->getMessage(), ['exception_class' => get_class($e2)]);
                         return null;
                     }
                 } else {
                     Log::error("batchRunReportsWithErrorHandling: Error 401 inicial Y falló el intento de refresh para user {$userId}.");
                     return null;
                 }
             } else {
                 Log::error("batchRunReportsWithErrorHandling: Error API GA (No 401, o ya reintentado, o diferente) para user {$userId}: " . $e->getMessage(), ['code' => $e->getCode(), 'google_errors' => $e->getErrors()]);
                 return null;
             }
        } catch (Throwable $e) {
             Log::error("batchRunReportsWithErrorHandling: Excepción general para user {$userId}: " . $e->getMessage(), ['exception_class' => get_class($e)]);
             return null;
        }
    }

    /**
     * [Experimental] Obtiene los datos de múltiples reportes GA4 en una sola llamada.
     *
     * @param GaConnection $connection
     * @param Carbon $startDate
     * @param Carbon $endDate
     * @param array $reportDefinitions Un array asociativo, ej:
     * [
     * 'basic' => ['metrics' => ['sessions', 'activeUsers']],
     * 'channels' => ['metrics' => ['sessions'], 'dimensions' => ['sessionDefaultChannelGroup']],
     * 'product_views' => ['metrics' => ['itemsViewed'], 'dimensions' => ['itemId', 'itemName'], 'limit' => 50, 'orderBy' => 'itemsViewed']
     * ]
     * @return array|null Un array asociativo con los resultados ['basic' => [...], 'channels' => [...], ...] o null si falla la llamada batch.
     */
     public function getMultipleReports(GaConnection $connection, Carbon $startDate, Carbon $endDate, array $reportDefinitions): ?array
     {
         $userId = $connection->user_id;
         Log::info("getMultipleReports: Iniciado para user {$userId}");
         $ga4PropertyId = $connection->property_id;
         if (!$ga4PropertyId) { Log::error("getMultipleReports: Falta property_id para user {$userId}."); return null; }

         $requests = [];
         $reportKeys = []; // Para mapear la respuesta de vuelta
         foreach ($reportDefinitions as $key => $def) {
             $metrics = [];
             foreach ($def['metrics'] as $metricName) { $metrics[] = new Metric(['name' => $metricName]); }
             $dimensions = [];
             foreach ($def['dimensions'] ?? [] as $dimName) { $dimensions[] = new Dimension(['name' => $dimName]); }
             $orderBys = [];
             if (isset($def['orderBy'])) {
                 $orderBys[] = new OrderBy([
                     'metric' => new MetricOrderBy(['metricName' => $def['orderBy']]),
                     'desc' => $def['desc'] ?? true, // Default desc
                 ]);
             }

             $requests[] = new RunReportRequest([
                 'dateRanges' => [new DateRange(['startDate' => $startDate->format('Y-m-d'), 'endDate' => $endDate->format('Y-m-d')])],
                 'metrics' => $metrics,
                 'dimensions' => $dimensions,
                 'orderBys' => $orderBys,
                 'limit' => $def['limit'] ?? 10000, // Un límite por defecto alto si no se especifica
             ]);
             $reportKeys[] = $key; // Guardar la clave original
         }

         if (empty($requests)) { return []; } // No hay reportes que pedir

         // Usar el handler de batch
         $batchResponse = $this->batchRunReportsWithErrorHandling($connection, $ga4PropertyId, $requests);

         if (!$batchResponse) {
             Log::error("getMultipleReports: Falló la llamada batchRunReportsWithErrorHandling para user {$userId}");
             return null; // La llamada batch falló completamente
         }

         $results = [];
         $reports = $batchResponse->getReports() ?? [];

         // Mapear respuestas a claves originales
         foreach ($reports as $index => $reportResponse) {
             $key = $reportKeys[$index] ?? 'unknown_' . $index; // Clave original o fallback
             Log::debug("Procesando respuesta para reporte '{$key}' (index {$index})");

             // Reutilizar la lógica de procesamiento existente si es posible, adaptándola
             // Aquí simplificaremos el procesamiento para el ejemplo:
             $rowsData = [];
             if ($reportResponse && $reportResponse->getRowCount() > 0) {
                foreach ($reportResponse->getRows() as $row) {
                    $dims = []; $mets = [];
                    foreach ($row->getDimensionValues() ?? [] as $dimVal) { $dims[] = $dimVal->getValue(); }
                    foreach ($row->getMetricValues() ?? [] as $metVal) { $mets[] = $metVal->getValue(); }
                    $rowsData[] = ['dimensions' => $dims, 'metrics' => $mets];
                }
                Log::info("Datos para reporte '{$key}' extraídos.", ['rows' => count($rowsData)]);
             } elseif ($reportResponse) {
                 Log::info("Reporte '{$key}' no devolvió filas.");
             } else {
                 Log::warning("Reporte '{$key}' resultó en null dentro de la respuesta batch.");
                 // Podríamos querer marcar este reporte específico como fallido
             }
             $results[$key] = $rowsData; // Asignar los datos procesados a la clave original
         }

         // Rellenar claves que pudieron faltar en la respuesta si es necesario
         foreach($reportKeys as $key) {
             if (!isset($results[$key])) {
                 Log::warning("No se encontró respuesta para el reporte '{$key}' en la respuesta batch.");
                 $results[$key] = []; // O null, o un indicador de error
             }
         }


         return $results;
     }


} // Fin de la clase GoogleAnalyticsService