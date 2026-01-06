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
            if (!$odessaTokenData
                ->odessaAfiliateAccount
                ?->customer
                ?->user) {
                
                Log::info('Token Odessa válido - Mostrando formulario de registro', [
                    'token_preview' => substr($odessaToken, 0, 20) . '...',
                    'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                    'seconds_remaining' => (int)floor($odessaTokenData->expiration->diffInSeconds(now(), true))
                ]);

                return Inertia::render(
                    'Auth/Register',
                    [
                        'genders' => Gender::casesWithLabels(),
                        'secondsLeft' => (int)floor($odessaTokenData->expiration->diffInSeconds(now(), true)),
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
        Log::info('Inicio de proceso de registro Odessa', [
            'token_length' => strlen($odessaToken),
            'email' => $request->email,
            'has_referrer' => !empty($request->referrer_id),
            'ip' => $request->ip(),
            'timestamp' => now()->toDateTimeString()
        ]);

        DB::beginTransaction();

        try {
            // Validar y decodificar el token
            $odessaTokenData = ($decodeOdessaTokenAction)($odessaToken);

            Log::debug('Token Odessa decodificado exitosamente', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'expires_at' => $odessaTokenData->expiration->toDateTimeString(),
                'time_remaining' => $odessaTokenData->expiration->diffForHumans()
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

            // Autenticar al usuario
            Auth::login($odessaAfiliateAccount->customer->user);

            // Registrar evento de tracking
            CompleteRegistration::track();

            Log::info('Registro Odessa completado exitosamente', [
                'user_id' => $odessaAfiliateAccount->customer->user->id,
                'odessa_afiliate_id' => $odessaAfiliateAccount->id,
                'email' => $request->email,
                'name' => $request->name,
                'registration_time' => now()->toDateTimeString()
            ]);

            DB::commit();

            return to_route('home')
                ->with('success', '¡Registro exitoso! Bienvenido a Famedic. Tu cuenta ha sido vinculada correctamente con Odessa.');

        } catch (ExpiredException $e) {
            DB::rollBack();
            
            Log::warning('Token Odessa expirado durante registro', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'error' => $e->getMessage(),
                'email' => $request->email,
                'ip' => $request->ip()
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'El enlace de registro ha expirado. Solicita un nuevo enlace a tu administrador.');

        } catch (OdessaAfiliateMemberAlreadyLinkedException $e) {
            DB::rollBack();
            
            Log::notice('Intento de registro con afiliado Odessa ya vinculado', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email' => $request->email,
                'existing_user_id' => $odessaTokenData->odessaAfiliateAccount->customer->user->id ?? 'unknown'
            ]);

            return redirect()
                ->route('login')
                ->with('info', 'Tu cuenta de Odessa ya está vinculada con Famedic. Puedes iniciar sesión con tus credenciales.');

        } catch (OdessaAfiliateMemberMismatchException $e) {
            DB::rollBack();
            
            Log::error('Mismatch en datos de afiliado Odessa', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email_provided' => $request->email,
                'error' => $e->getMessage(),
                'ip' => $request->ip()
            ]);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Los datos proporcionados no coinciden con tu cuenta de Odessa. Verifica tu información o contacta con soporte.');

        } catch (OdessaIdAlreadyLinkedException $e) {
            DB::rollBack();
            
            Log::error('ID de Odessa ya vinculado a otro usuario', [
                'odessa_afiliate_id' => $odessaTokenData->odessaAfiliateAccount->id ?? 'unknown',
                'email_attempt' => $request->email,
                'error' => $e->getMessage()
            ]);

            return redirect()
                ->route('login')
                ->with('error', 'Esta cuenta de Odessa ya está asociada a otro usuario de Famedic. Contacta con soporte si crees que esto es un error.');

        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error inesperado en registro Odessa', [
                'token_preview' => substr($odessaToken, 0, 20) . '...',
                'email' => $request->email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip()
            ]);

            report($e);

            return redirect()
                ->back()
                ->withInput()
                ->with('error', 'Ocurrió un error inesperado durante el registro. Por favor, intenta nuevamente o contacta con soporte si el problema persiste.');
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