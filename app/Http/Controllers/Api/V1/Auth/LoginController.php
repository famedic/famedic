<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\IssueAuthOtpAction;
use App\Actions\Api\V1\Auth\VerifyAuthOtpAction;
use App\Exceptions\Api\V1\Auth\AuthOtpVerificationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequestCodeRequest;
use App\Http\Requests\Api\V1\Auth\LoginVerifyCodeRequest;
use App\Http\Responses\ApiResponse;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function __construct(
        private IssueAuthOtpAction $issueAuthOtpAction,
        private VerifyAuthOtpAction $verifyAuthOtpAction,
        private IssueAkubicaTokenAction $issueAkubicaTokenAction,
    ) {}

    public function requestCode(LoginRequestCodeRequest $request): JsonResponse
    {
        $email = strtolower($request->validated('email'));
        $user = User::query()->where('email', $email)->first();

        try {
            $result = ($this->issueAuthOtpAction)(
                email: $email,
                purpose: OtpCode::PURPOSE_AKUBICA_LOGIN,
                notifiable: $user,
            );
        } catch (\Throwable $e) {
            Log::error('akubica_login_request_code_failed', [
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

    public function verifyCode(LoginVerifyCodeRequest $request): JsonResponse
    {
        $email = strtolower($request->validated('email'));
        $code = $request->validated('code');

        try {
            ($this->verifyAuthOtpAction)($email, $code, OtpCode::PURPOSE_AKUBICA_LOGIN);
        } catch (AuthOtpVerificationException $e) {
            return ApiResponse::error($e->errorCode, $e->getMessage(), $e->httpStatus);
        }

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return ApiResponse::error(
                'INVALID_CODE',
                'El código ingresado no es válido.',
                422,
            );
        }

        $tokenData = ($this->issueAkubicaTokenAction)($user);

        return ApiResponse::success([
            ...$tokenData,
            'user' => $this->formatUser($user),
        ]);
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
