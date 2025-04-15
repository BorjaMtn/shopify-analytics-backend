<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Models\Shop;
use App\Models\GaConnection; // <-- Importar modelo GA
use App\Http\Requests\SaveShopifyTokenRequest;
use App\Http\Requests\SaveGaPropertyRequest; // <-- Importar nuevo request
use Illuminate\Support\Facades\Auth;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException; // Para errores OAuth
use Carbon\Carbon; // <-- Importar Carbon
use Illuminate\Validation\ValidationException; // Para validar el 'code'
use Illuminate\Database\Eloquent\ModelNotFoundException; // Para manejar si no existe conexión GA

class ConnectionController extends Controller
{
    // --- saveShopifyToken (Implementado) ---
    public function saveShopifyToken(SaveShopifyTokenRequest $request): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();
            if (!$user) { return response()->json(['message' => 'Error: Usuario no autenticado.'], 401); }
            $validatedData = $request->validated();
            $shop = Shop::updateOrCreate(
                ['user_id' => $user->id],
                ['shop_domain' => $validatedData['shop_domain'], 'access_token' => $validatedData['access_token']]
            );
            Log::info("Shopify connection saved/updated for user {$user->id}. Shop ID: {$shop->id}");
            return response()->json([ 'message' => 'Conexión con Shopify guardada correctamente.', 'shop' => $shop->refresh()->makeHidden('access_token')], 200);
        } catch (\Exception $e) {
            Log::error("Error saving Shopify connection for user " . (Auth::guard('api')->id() ?? 'N/A') . ": " . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error interno al guardar la conexión con Shopify.'], 500);
        }
    }


    // --- MÉTODO redirectToGoogle (Implementado - Devuelve JSON) ---
    public function redirectToGoogle(): JsonResponse
    {
        $userId = Auth::guard('api')->id() ?? 'N/A';
        Log::info("redirectToGoogle: Solicitud iniciada por user: " . $userId);
        try {
            $provider = new Google([ 'clientId'=>config('services.google.client_id'),'clientSecret'=>config('services.google.client_secret'),'redirectUri'=>config('services.google.redirect'), ]);
            $options = [ 'scope'=>config('services.google.scopes',[]),'access_type'=>config('services.google.options.access_type','offline'),'prompt'=>config('services.google.options.prompt','consent'), /* TODO: STATE */ ];
            $authorizationUrl = $provider->getAuthorizationUrl($options);
            Log::info("redirectToGoogle: URL generada: " . $authorizationUrl);
            return response()->json(['authorization_url' => $authorizationUrl]);
        } catch (\Exception $e) {
            Log::error("redirectToGoogle: Excepción capturada para user {$userId}: " . $e->getMessage(), ['exception' => $e]);
            return response()->json(['message' => 'Error al iniciar conexión con Google.'], 500);
        }
    }


    // --- MÉTODO handleGoogleCallback (IMPLEMENTADO) ---
    /**
     * Maneja el callback de Google después de la autorización,
     * recibiendo el código desde el frontend vía POST.
     */
    public function handleGoogleCallback(Request $request): JsonResponse
    {
         $userId = Auth::guard('api')->id() ?? 'N/A'; // Ojo, si falla el guard, será N/A
         Log::info("handleGoogleCallback: Recibido para user: " . $userId, ['body' => $request->all()]);

         // 1. Validar 'code'
         try {
             $validated = $request->validate(['code' => 'required|string']);
             $code = $validated['code'];
             // TODO: Validar parámetro 'state'
         } catch (ValidationException $e) {
             Log::error('handleGoogleCallback: Código de autorización faltante o inválido.', ['user_id' => $userId, 'errors' => $e->errors()]);
             return response()->json(['message' => 'Código de autorización inválido.'], 422);
         }

         // 2. Obtener usuario autenticado (necesario para asociar tokens)
         $user = Auth::guard('api')->user();
         if (!$user) {
             Log::error('handleGoogleCallback: Usuario no autenticado (Auth::guard(\'api\')->user() devolvió null).');
             return response()->json(['message' => 'No autenticado.'], 401);
         }

         // 3. Preparar provider OAuth
         try {
             $provider = new Google([/* ... credenciales ... */
                 'clientId'=>config('services.google.client_id'),'clientSecret'=>config('services.google.client_secret'),'redirectUri'=>config('services.google.redirect'),
             ]);
         } catch (\Exception $e) {
            Log::error("handleGoogleCallback: Error instantiating Google OAuth Provider: " . $e->getMessage());
            return response()->json(['message' => 'Error interno configurando conexión con Google.'], 500);
         }

         // 4. Intercambiar código por tokens y guardar
         try {
             Log::info("handleGoogleCallback: Intercambiando código por token para user: {$user->id}");
             $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
             Log::info("handleGoogleCallback: Tokens recibidos de Google para user: {$user->id}");

             $accessToken = $token->getToken();
             $refreshToken = $token->getRefreshToken();
             $expiresIn = $token->getExpires();
             $expiresAt = $expiresIn ? Carbon::now()->addSeconds($expiresIn) : null;

             $gaConnection = GaConnection::updateOrCreate(
                 ['user_id' => $user->id],
                 [
                     'access_token' => $accessToken, // Encriptado por Cast
                     'refresh_token' => $refreshToken ?? $user->gaConnection?->getRawOriginal('refresh_token'), // Conservar viejo si no viene nuevo
                     'expires_at' => $expiresAt,
                     // property_id se guarda en otro endpoint
                 ]
             );

             if ($gaConnection->wasRecentlyCreated && !$refreshToken) { Log::warning("..."); }
             else if ($refreshToken) { Log::info("handleGoogleCallback: Refresh token guardado/actualizado para user {$user->id}."); }
             Log::info("handleGoogleCallback: Conexión GA guardada/actualizada para user {$user->id}. GaConnection ID: {$gaConnection->id}");

             return response()->json(['message' => 'Google Analytics conectado correctamente.'], 200);

         } catch (IdentityProviderException $e) {
             Log::error('handleGoogleCallback: IdentityProviderException para user ' . $user->id . ': ' . $e->getMessage());
             return response()->json(['message' => 'Error al verificar con Google: ' . $e->getMessage()], 400);
         } catch (\Exception $e) {
             Log::error("handleGoogleCallback: Excepción general para user {$user->id}: " . $e->getMessage(), ['exception' => $e]);
             return response()->json(['message' => 'Error interno al guardar la conexión con Google.'], 500);
         }
    }
    // -------------------------------------------------


    // --- MÉTODO saveGaPropertyId (Implementado antes) ---
     /**
      * Guarda o actualiza el ID de propiedad de GA4 para la conexión existente del usuario.
      */
     public function saveGaPropertyId(SaveGaPropertyRequest $request): JsonResponse
     {
        // ... (código que implementamos antes para guardar property_id) ...
         $user=Auth::guard('api')->user();if(!$user){return response()->json(['message'=>'No autenticado.'],401);} $validatedData=$request->validated();$gaPropertyId=$validatedData['property_id']; try{$gaConnection=$user->gaConnection()->firstOrFail();$gaConnection->update(['property_id'=>$gaPropertyId]);Log::info("GA4 Property ID actualizado para user {$user->id} a {$gaPropertyId}");return response()->json(['message'=>'ID de Propiedad de Google Analytics guardado correctamente.','connection'=>$gaConnection->refresh()->makeHidden(['access_token','refresh_token'])],200);}catch(ModelNotFoundException $e){Log::warning("Intento de guardar property_id GA4 sin conexión previa para user {$user->id}");return response()->json(['message'=>'Primero debes conectar tu cuenta de Google Analytics.'],404);}catch(\Exception $e){Log::error("Error al actualizar property_id GA4 para user {$user->id}: ".$e->getMessage(),['exception'=>$e]);return response()->json(['message'=>'Error interno al guardar el ID de Propiedad.'],500);}
     }
     // -----------------------------------------

} // Fin de la clase