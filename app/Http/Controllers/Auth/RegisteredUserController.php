<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Register\RegisterRegularCustomerAction;
use App\Enums\Gender;
use App\Data\StatesMexico;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use App\Services\Tracking\CompleteRegistration;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    public function create(): Response
    {
        Log::channel('single')->info('📱 REGISTER: Página de registro cargada (create)', [
            'action' => 'create',
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'genders_count' => count(Gender::casesWithLabels()),
            'states_count' => count(StatesMexico::todos()),
            'url' => request()->fullUrl(),
        ]);

        return Inertia::render('Auth/Register', [
            'genders' => Gender::casesWithLabels(),
            'states' => StatesMexico::todos(),
        ]);
    }

    public function createFromInvitation(User $user): Response
    {
        Log::channel('single')->info('📩 REGISTER: Página de registro por invitación', [
            'action' => 'createFromInvitation',
            'ip' => request()->ip(),
            'inviter_id' => $user->id,
            'inviter_name' => $user->full_name,
            'user_agent' => request()->userAgent(),
        ]);

        return Inertia::render('Auth/Register', [
            'genders' => Gender::casesWithLabels(),
            'states' => StatesMexico::todos(),
            'inviter' => [
                'id' => $user->id,
                'name' => $user->full_name,
            ],
        ]);
    }

    public function store(RegisterRequest $request, RegisterRegularCustomerAction $action): RedirectResponse
    {
        // LOG 1: Inicio del proceso
        Log::channel('single')->info('🚀 REGISTER: Iniciando proceso de registro (store)', [
            'action' => 'store',
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'method' => $request->method(),
            'has_referrer' => !empty($request->referrer_id),
            'referrer_id' => $request->referrer_id,
            'total_fields' => count($request->all()),
            'field_names' => array_keys($request->all()),
        ]);

        // LOG 2: Datos recibidos (con información sensible enmascarada)
        Log::channel('single')->debug('📦 REGISTER: Datos recibidos del formulario', [
            'name' => $request->name,
            'paternal_lastname' => $request->paternal_lastname,
            'maternal_lastname' => $request->maternal_lastname,
            'email' => $request->email,
            'phone_masked' => $request->phone ? substr($request->phone, 0, 3) . '****' . substr($request->phone, -3) : null,
            'gender' => $request->gender,
            'state' => $request->state,
            'birth_date' => $request->birth_date,
            'phone_country' => $request->phone_country,
            'has_password' => !empty($request->password),
            'has_password_confirmation' => !empty($request->password_confirmation),
            'has_recaptcha' => !empty($request->g_recaptcha_response),
            'recaptcha_length' => strlen($request->g_recaptcha_response ?? ''),
        ]);

        try {
            // LOG 3: Antes de llamar a la acción
            Log::channel('single')->info('🔧 REGISTER: Llamando a RegisterRegularCustomerAction', [
                'step' => 'before_action',
                'email' => $request->email,
                'parsed_birth_date' => Carbon::parse($request->birth_date)->toDateString(),
            ]);

            $regularAccount = $action(
                name: $request->name,
                paternalLastname: $request->paternal_lastname,
                maternalLastname: $request->maternal_lastname,
                birthDate: Carbon::parse($request->birth_date),
                gender: Gender::from($request->gender),
                state: $request->state,
                phone: $request->phone,
                phoneCountry: $request->phone_country,
                email: $request->email,
                password: $request->password,
                referrerUserId: $request->referrer_id,
            );

            // LOG 4: Después de crear la cuenta
            Log::channel('single')->info('✅ REGISTER: Usuario creado exitosamente', [
                'step' => 'account_created',
                'user_id' => $regularAccount->customer->user->id ?? 'NO_ID',
                'user_email' => $regularAccount->customer->user->email ?? 'NO_EMAIL',
                'account_type' => get_class($regularAccount),
                'customer_id' => $regularAccount->customer->id ?? 'NO_CUSTOMER_ID',
            ]);

            // LOG 5: Antes de autenticar
            Log::channel('single')->debug('🔐 REGISTER: Intentando autenticar usuario', [
                'step' => 'before_auth',
                'user_id_to_auth' => $regularAccount->customer->user->id ?? 'NO_ID',
            ]);

            Auth::login($regularAccount->customer->user);

            // LOG 6: Después de autenticar
            Log::channel('single')->info('🔓 REGISTER: Usuario autenticado', [
                'step' => 'after_auth',
                'authenticated_user_id' => Auth::id(),
                'is_authenticated' => Auth::check(),
                'auth_via_remember' => Auth::viaRemember(),
            ]);

            // LOG 7: Antes de tracking
            Log::channel('single')->debug('📊 REGISTER: Ejecutando CompleteRegistration::track()', [
                'step' => 'before_tracking',
            ]);

            CompleteRegistration::track();

            // LOG 8: Registro completado
            Log::channel('single')->info('🎉 REGISTER: Registro completado exitosamente', [
                'step' => 'completed',
                'user_id' => Auth::id(),
                'redirect_to' => 'home',
                'session_id' => session()->getId(),
            ]);

            return to_route('home')
                ->flashMessage('Registro exitoso. ¡Bienvenido a Famedic!');

        } catch (\Throwable $e) {
            // LOG 9: Error en el proceso
            Log::channel('single')->error('❌ REGISTER: Error en el proceso de registro', [
                'step' => 'error',
                'error_message' => $e->getMessage(),
                'error_file' => $e->getFile(),
                'error_line' => $e->getLine(),
                'error_code' => $e->getCode(),
                'error_class' => get_class($e),
                'user_data' => [
                    'email' => $request->email,
                    'name' => $request->name,
                    'phone_masked' => $request->phone ? substr($request->phone, 0, 3) . '****' : null,
                ],
                'trace' => $e->getTraceAsString(), // Solo en desarrollo
            ]);

            // Re-lanzar la excepción
            throw $e;
        }
    }
}