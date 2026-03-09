<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Odessa\DecodeOdessaTokenAction;
use App\Actions\Odessa\RegisterOdessaAfiliateCustomerAction;
use App\Enums\Gender;
use App\Exceptions\OdessaAfiliateMemberAlreadyLinkedException;
use App\Exceptions\OdessaAfiliateMemberMismatchException;
use App\Exceptions\OdessaIdAlreadyLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Services\Tracking\CompleteRegistration;
use Carbon\Carbon;
use Firebase\JWT\ExpiredException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

class OdessaRegisterController extends Controller
{
    /**
     * Muestra el formulario de registro para usuarios que vienen desde Odessa
     * con un token válido.
     */
    public function index(Request $request, string $odessaToken, DecodeOdessaTokenAction $decodeOdessaTokenAction)
    {
        Log::info('Acceso a formulario de registro Odessa', [
            'token_length' => strlen($odessaToken),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'timestamp' => now()->toDateTimeString()
        ]);

        try {
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            // Verificar si el afiliado ya tiene usuario vinculado
            if (
                !$odessaTokenData
                    ->odessaAfiliateAccount
                    ?->customer
                        ?->user
            ) {

                Log::info('Token Odessa válido - Mostrando formulario de registro', [
                    'token_preview' => substr($odessaToken, 0, 20) . '...',
                    'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                    'seconds_remaining' => (int) floor($odessaTokenData->expiration->diffInSeconds(now(), true))
                ]);

                return Inertia::render(
                    'Auth/Register',
                    [
                        'genders' => Gender::casesWithLabels(),
                        'secondsLeft' => (int) floor($odessaTokenData->expiration->diffInSeconds(now(), true)),
                        'odessaToken' => $odessaToken,
                    ]
                );
            }

            // El afiliado ya tiene usuario vinculado
            Log::notice('Intento de acceso con token ya vinculado', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'existing_user_id' => $odessaTokenData->odessaAfiliateAccount->customer->user->id ?? 'unknown'
            ]);

            return redirect()
                ->route('login')
                ->with('info', 'Tu cuenta de Odessa ya está vinculada con Famedic. Puedes iniciar sesión con tus credenciales.');

        } catch (ExpiredException $e) {
            Log::warning('Token Odessa expirado al acceder al formulario', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'El enlace de registro ha expirado. Solicita un nuevo enlace a tu administrador.');

        } catch (\Exception $e) {
            Log::error('Error inesperado en formulario de registro Odessa', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'Ocurrió un error al procesar tu solicitud. Por favor, intenta nuevamente.');
        }
    }

    /**
     * Procesa el registro de un nuevo usuario desde Odessa.
     */
    public function store(
        RegisterRequest $request,
        string $odessaToken,
        DecodeOdessaTokenAction $decodeOdessaTokenAction,
        RegisterOdessaAfiliateCustomerAction $registerOdessaAfiliateMemberAction
    ) {
        // LOG 1: Confirmación de que llegó al método store
        Log::info('🔵 [ODESSA_REGISTRO] Inicio del método store()', [
            'timestamp' => now()->toDateTimeString(),
            'endpoint' => request()->fullUrl(),
            'method' => request()->method(),
            'ip' => $request->ip(),
            'user_agent' => request()->userAgent(),
            'has_token' => !empty($odessaToken),
            'token_length' => strlen($odessaToken),
            'email' => $request->email,
            'has_referrer' => !empty($request->referrer_id),
            'request_all_keys' => array_keys($request->all()),
            'request_headers_keys' => array_keys($request->headers->all())
        ]);

        // LOG 2: Validar estructura básica del token antes de procesar
        if (empty($odessaToken)) {
            Log::warning('⚠️ [ODESSA_REGISTRO] Token vacío recibido', [
                'email' => $request->email,
                'ip' => $request->ip()
            ]);
        } else {
            Log::debug('🔍 [ODESSA_REGISTRO] Token recibido (primeros 30 chars)', [
                'token_preview' => substr($odessaToken, 0, 30) . (strlen($odessaToken) > 30 ? '...' : ''),
                'full_token_length' => strlen($odessaToken),
                'token_starts_with' => substr($odessaToken, 0, 10)
            ]);
        }

        // LOG 3: Validación de datos de entrada
        Log::debug('📋 [ODESSA_REGISTRO] Datos de entrada recibidos', [
            'name' => $request->name,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'gender' => $request->gender,
            'phone_country' => $request->phone_country,
            'referrer_id' => $request->referrer_id,
            'total_fields_received' => count($request->all())
        ]);

        DB::beginTransaction();

        // LOG 4: Confirmación de inicio de transacción
        Log::debug('🔄 [ODESSA_REGISTRO] Transacción de base de datos iniciada', [
            'transaction_start' => now()->toDateTimeString()
        ]);

        try {
            // LOG 5: Antes de decodificar el token
            Log::info('🔑 [ODESSA_REGISTRO] Iniciando decodificación del token Odessa', [
                'action_class' => get_class($decodeOdessaTokenAction),
                'token_hash' => hash('sha256', $odessaToken)
            ]);

            // Validar y decodificar el token
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            // LOG 6: Después de decodificar el token exitosamente
            Log::info('✅ [ODESSA_REGISTRO] Token Odessa decodificado exitosamente', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'afiliate_email' => $odessaTokenData->odessaAfiliateAccount->email ?? 'unknown',
                'expires_at' => $odessaTokenData->expiration->toDateTimeString(),
                'time_remaining' => $odessaTokenData->expiration->diffForHumans(),
                'is_expired' => $odessaTokenData->expiration->isPast(),
                'token_type' => get_class($odessaTokenData)
            ]);

            // LOG 7: Antes de registrar al afiliado
            Log::info('👤 [ODESSA_REGISTRO] Iniciando registro de afiliado Odessa', [
                'action_class' => get_class($registerOdessaAfiliateMemberAction),
                'email_provided' => $request->email,
                'email_from_token' => $odessaTokenData->odessaAfiliateAccount->email ?? 'unknown',
                'email_match' => ($request->email === ($odessaTokenData->odessaAfiliateAccount->email ?? null))
            ]);

            // Registrar al afiliado de Odessa como usuario
            $odessaAfiliateAccount = ($registerOdessaAfiliateMemberAction)(
                name: $request->name,
                paternalLastname: $request->paternal_lastname,
                maternalLastname: $request->maternal_lastname,
                birthDate: Carbon::parse($request->birth_date),
                gender: Gender::from($request->gender),
                phone: $request->phone,
                phoneCountry: $request->phone_country,
                email: $request->email,
                password: $request->password,
                odessaTokenData: $odessaTokenData
            );

            // LOG 8: Después de registrar exitosamente
            Log::info('✅ [ODESSA_REGISTRO] Afiliado registrado exitosamente', [
                'user_id' => $odessaAfiliateAccount->customer->user->id ?? 'unknown',
                'user_email' => $odessaAfiliateAccount->customer->user->email ?? 'unknown',
                'odessa_afiliate_id' => $odessaAfiliateAccount->id,
                'customer_id' => $odessaAfiliateAccount->customer->id ?? 'unknown',
                'created_at' => $odessaAfiliateAccount->created_at ?? 'unknown'
            ]);

            // LOG 9: Antes de autenticar
            Log::debug('🔐 [ODESSA_REGISTRO] Intentando autenticar usuario', [
                'user_id_to_auth' => $odessaAfiliateAccount->customer->user->id ?? 'unknown'
            ]);

            // Autenticar al usuario
            Auth::login($odessaAfiliateAccount->customer->user);

            // LOG 10: Confirmación de autenticación
            Log::info('✅ [ODESSA_REGISTRO] Usuario autenticado exitosamente', [
                'authenticated_user_id' => Auth::id(),
                'is_authenticated' => Auth::check(),
                'session_id' => session()->getId()
            ]);

            // Registrar evento de tracking
            CompleteRegistration::track();

            // LOG 11: Antes de commit
            Log::debug('💾 [ODESSA_REGISTRO] Intentando commit de transacción', [
                'pending_operations' => 'user_creation, afiliate_linking, auth_session'
            ]);

            DB::commit();

            // LOG 12: Confirmación de commit exitoso
            Log::info('✅ [ODESSA_REGISTRO] Transacción completada exitosamente', [
                'transaction_committed_at' => now()->toDateTimeString(),
                'total_process_time_ms' => microtime(true) - LARAVEL_START
            ]);

            // LOG 13: Resumen final exitoso
            Log::info('🎉 [ODESSA_REGISTRO] Proceso de registro COMPLETADO EXITOSAMENTE', [
                'user_id' => $odessaAfiliateAccount->customer->user->id,
                'odessa_afiliate_id' => $odessaAfiliateAccount->id,
                'email' => $request->email,
                'name' => $request->name,
                'registration_time' => now()->toDateTimeString(),
                'redirect_to' => 'home'
            ]);

            return to_route('home')
                ->with('success', '¡Registro exitoso! Bienvenido a Famedic. Tu cuenta ha sido vinculada correctamente con Odessa.');

        } catch (ExpiredException $e) {
            DB::rollBack();

            Log::warning('⚠️ [ODESSA_REGISTRO] Token Odessa expirado durante registro', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'error' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'email' => $request->email,
                'ip' => $request->ip(),
                'step_failed' => 'token_decoding'
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'El enlace de registro ha expirado. Solicita un nuevo enlace a tu administrador.');

        } catch (OdessaAfiliateMemberAlreadyLinkedException $e) {
            DB::rollBack();

            Log::notice('ℹ️ [ODESSA_REGISTRO] Intento de registro con afiliado Odessa ya vinculado', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email' => $request->email,
                'existing_user_id' => $odessaTokenData->odessaAfiliateAccount->customer->user->id ?? 'unknown',
                'error' => $e->getMessage(),
                'step_failed' => 'member_registration'
            ]);

            return redirect()
                ->route('login')
                ->with('info', 'Tu cuenta de Odessa ya está vinculada con Famedic. Puedes iniciar sesión con tus credenciales.');

        } catch (OdessaAfiliateMemberMismatchException $e) {
            DB::rollBack();

            Log::error('❌ [ODESSA_REGISTRO] Mismatch en datos de afiliado Odessa', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email_provided' => $request->email,
                'email_in_token' => $odessaTokenData->odessaAfiliateAccount->email ?? 'unknown',
                'error' => $e->getMessage(),
                'ip' => $request->ip(),
                'step_failed' => 'data_validation'
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Los datos proporcionados no coinciden con tu cuenta de Odessa. Verifica tu información o contacta con soporte.');

        } catch (OdessaIdAlreadyLinkedException $e) {
            DB::rollBack();

            Log::error('❌ [ODESSA_REGISTRO] ID de Odessa ya vinculado a otro usuario', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email_attempt' => $request->email,
                'error' => $e->getMessage(),
                'existing_user_id' => $odessaTokenData->odessaAfiliateAccount->customer->user->id ?? 'unknown',
                'step_failed' => 'unique_validation'
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'Esta cuenta de Odessa ya está asociada a otro usuario de Famedic. Contacta con soporte si crees que esto es un error.');

        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('💥 [ODESSA_REGISTRO] Error inesperado en registro Odessa', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'email' => $request->email,
                'error_class' => get_class($e),
                'error_message' => $e->getMessage(),
                'error_code' => $e->getCode(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'ip' => $request->ip(),
                'trace_snippet' => substr($e->getTraceAsString(), 0, 500),
                'step_failed' => 'unknown',
                'timestamp' => now()->toDateTimeString()
            ]);

            report($e);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ocurrió un error inesperado durante el registro. Por favor, intenta nuevamente o contacta con soporte si el problema persiste.');
        } finally {
            // LOG 14: Finalización del método (siempre se ejecuta)
            Log::debug('🏁 [ODESSA_REGISTRO] Método store() finalizado', [
                'execution_completed_at' => now()->toDateTimeString(),
                'memory_usage_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
                'total_execution_time_ms' => round((microtime(true) - LARAVEL_START) * 1000, 2)
            ]);
        }
    }

    /**
     * Método para regenerar token (opcional - si necesitas esta funcionalidad)
     */
    public function regenerateToken(Request $request, string $oldToken)
    {
        Log::info('Solicitud de regeneración de token Odessa', [
            'old_token_preview' => substr($oldToken, 0, 20) . '...',
            'email' => $request->user() ? $request->user()->email : 'guest',
            'ip' => $request->ip()
        ]);

        // Aquí iría la lógica para regenerar el token si es necesario
        // Por ejemplo, validar permisos y generar nuevo token

        return redirect()
            ->back()
            ->with('info', 'Solicitud de nuevo token recibida. Contacta con tu administrador para obtener un nuevo enlace.');
    }
}