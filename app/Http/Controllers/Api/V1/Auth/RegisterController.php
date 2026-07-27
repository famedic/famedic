<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\IssueAuthOtpAction;
use App\Actions\Api\V1\Auth\RegisterAkubicaCustomerAction;
use App\Actions\Api\V1\Auth\VerifyAuthOtpAction;
use App\Exceptions\Api\V1\Auth\AuthOtpVerificationException;
use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\RegisterVerifyCodeRequest;
use App\Http\Requests\Api\V1\Auth\SecureRegisterRequest;
use App\Http\Requests\Api\V1\Auth\SecureRegisterResendCodeRequest;
use App\Http\Requests\Api\V1\Auth\SecureRegisterVerifyCodeRequest;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Otp\Registration\AkubicaRegisterOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;

class RegisterController extends Controller
{
    public function __construct(
        private IssueAuthOtpAction $issueAuthOtpAction,
        private VerifyAuthOtpAction $verifyAuthOtpAction,
        private RegisterAkubicaCustomerAction $registerAkubicaCustomerAction,
        private IssueAkubicaTokenAction $issueAkubicaTokenAction,
        private AkubicaRegisterOtpService $akubicaRegisterOtpService,
        private OtpExceptionHttpMapper $otpExceptionHttpMapper,
    ) {}

    public function store(Request $request): JsonResponse
    {
        if (AkubicaRegisterOtpService::isEnabled()) {
            return $this->storeP0a($request);
        }

        return $this->storeLegacy($request);
    }

    public function verifyCode(Request $request): JsonResponse
    {
        if (AkubicaRegisterOtpService::isEnabled()) {
            return $this->verifyCodeP0a($request);
        }

        $form = RegisterVerifyCodeRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        return $this->verifyCodeLegacy($form);
    }

    private function verifyCodeP0a(Request $request): JsonResponse
    {
        $form = SecureRegisterVerifyCodeRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        try {
            $this->akubicaRegisterOtpService->assertConfigurationReady();
            $payload = $this->akubicaRegisterOtpService->verify(
                $form->validated('challenge_id'),
                $form->validated('code'),
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            return $this->otpExceptionHttpMapper->toResponse($e);
        } catch (\Throwable $e) {
            Log::error('akubica_register_verify_p0a_failed', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'INTERNAL_ERROR',
                'Error interno del servidor.',
                500,
            );
        }

        return ApiResponse::success($payload);
    }

    public function resendCode(SecureRegisterResendCodeRequest $request): JsonResponse
    {
        if (! AkubicaRegisterOtpService::isEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El reenvio OTP P0-A de registro no esta habilitado.',
                503,
            );
        }

        try {
            $this->akubicaRegisterOtpService->assertConfigurationReady();
            $payload = $this->akubicaRegisterOtpService->resend(
                $request->validated('challenge_id'),
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            return $this->otpExceptionHttpMapper->toResponse($e);
        }

        return ApiResponse::success($payload, null, 202);
    }

    private function storeLegacy(Request $request): JsonResponse
    {
        $form = RegisterRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        $data = $form->validated();
        $email = strtolower($data['email']);
        $phoneCountry = $data['phone_country'] ?? 'MX';

        if (User::query()->where('email', $email)->exists()) {
            return ApiResponse::error(
                'EMAIL_ALREADY_REGISTERED',
                'El correo electrónico ya está registrado.',
                409,
            );
        }

        if ($this->phoneAlreadyRegistered($data['phone'], $phoneCountry)) {
            return ApiResponse::error(
                'PHONE_ALREADY_REGISTERED',
                'El teléfono ya está registrado.',
                409,
            );
        }

        $payload = [
            'email' => $email,
            'phone' => $data['phone'],
            'full_name' => $data['full_name'],
            'phone_country' => $phoneCountry,
        ];

        try {
            $result = ($this->issueAuthOtpAction)(
                email: $email,
                purpose: OtpCode::PURPOSE_AKUBICA_REGISTER,
                payload: $payload,
                notifiable: $email,
            );
        } catch (\Throwable $e) {
            Log::error('akubica_register_request_code_failed', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'DELIVERY_FAILED',
                'No se pudo enviar el código de verificación.',
                503,
            );
        }

        return ApiResponse::success($result);
    }

    private function storeP0a(Request $request): JsonResponse
    {
        $form = SecureRegisterRequest::createFrom($request);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->validateResolved();

        try {
            $this->akubicaRegisterOtpService->assertConfigurationReady();
            $payload = $this->akubicaRegisterOtpService->request(
                $form->registrationIdentity(),
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            return $this->otpExceptionHttpMapper->toResponse($e);
        } catch (OtpIdentityNormalizationException $e) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos de registro no son validos.',
                422,
            );
        }

        return ApiResponse::success($payload, null, 202);
    }

    private function verifyCodeLegacy(RegisterVerifyCodeRequest $request): JsonResponse
    {
        $email = strtolower($request->validated('email'));
        $code = $request->validated('code');

        try {
            $otp = ($this->verifyAuthOtpAction)($email, $code, OtpCode::PURPOSE_AKUBICA_REGISTER);
        } catch (AuthOtpVerificationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $payload = $otp->payload;

        if (! is_array($payload) || empty($payload['email']) || empty($payload['phone']) || empty($payload['full_name'])) {
            return ApiResponse::error(
                'INTERNAL_ERROR',
                'Error interno del servidor.',
                500,
            );
        }

        if (User::query()->where('email', $email)->exists()) {
            return ApiResponse::error(
                'EMAIL_ALREADY_REGISTERED',
                'El correo electrónico ya está registrado.',
                409,
            );
        }

        if ($this->phoneAlreadyRegistered($payload['phone'], $payload['phone_country'] ?? 'MX')) {
            return ApiResponse::error(
                'PHONE_ALREADY_REGISTERED',
                'El teléfono ya está registrado.',
                409,
            );
        }

        try {
            $user = ($this->registerAkubicaCustomerAction)($payload);
        } catch (\Throwable $e) {
            Log::error('akubica_register_verify_failed', [
                'error' => $e->getMessage(),
            ]);

            return ApiResponse::error(
                'INTERNAL_ERROR',
                'Error interno del servidor.',
                500,
            );
        }

        $tokenData = ($this->issueAkubicaTokenAction)($user);

        return ApiResponse::success([
            ...$tokenData,
            'user' => $this->formatUser($user),
        ]);
    }

    private function phoneAlreadyRegistered(string $phone, string $phoneCountry): bool
    {
        try {
            $normalizedPhone = str_replace(' ', '', (new PhoneNumber($phone, $phoneCountry))->formatNational());
        } catch (\Throwable) {
            return false;
        }

        return User::query()
            ->where('phone', $normalizedPhone)
            ->where('phone_country', $phoneCountry)
            ->exists();
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => trim($user->full_name) ?: $user->name,
        ];
    }
}
