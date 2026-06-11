<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\IssueAuthOtpAction;
use App\Actions\Api\V1\Auth\RegisterAkubicaCustomerAction;
use App\Actions\Api\V1\Auth\VerifyAuthOtpAction;
use App\Exceptions\Api\V1\Auth\AuthOtpVerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Requests\Api\V1\Auth\RegisterVerifyCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Propaganistas\LaravelPhone\PhoneNumber;

class RegisterController extends Controller
{
    public function __construct(
        private IssueAuthOtpAction $issueAuthOtpAction,
        private VerifyAuthOtpAction $verifyAuthOtpAction,
        private RegisterAkubicaCustomerAction $registerAkubicaCustomerAction,
        private IssueAkubicaTokenAction $issueAkubicaTokenAction,
    ) {}

    public function store(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();
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
                'email' => $email,
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

    public function verifyCode(RegisterVerifyCodeRequest $request): JsonResponse
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
                'email' => $email,
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
