<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Actions\Api\V1\Auth\IssueAkubicaTokenAction;
use App\Actions\Api\V1\Auth\IssueAuthOtpAction;
use App\Actions\Api\V1\Auth\VerifyAuthOtpAction;
use App\Exceptions\Api\V1\Auth\AuthOtpVerificationException;
use App\Exceptions\Otp\OtpChallengeException;
use App\Exceptions\Otp\OtpConfigurationException;
use App\Exceptions\Otp\OtpIdentityNormalizationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginOtpResendRequest;
use App\Http\Requests\Api\V1\Auth\LoginRequestCodeRequest;
use App\Http\Requests\Api\V1\Auth\LoginVerifyCodeRequest;
use App\Http\Responses\Api\V1\OtpExceptionHttpMapper;
use App\Http\Responses\ApiResponse;
use App\Models\OtpChallenge;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\AuthOtpAuditRecorder;
use App\Services\Otp\AkubicaLoginOtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class LoginController extends Controller
{
    public function __construct(
        private IssueAuthOtpAction $issueAuthOtpAction,
        private VerifyAuthOtpAction $verifyAuthOtpAction,
        private IssueAkubicaTokenAction $issueAkubicaTokenAction,
        private AkubicaLoginOtpService $akubicaLoginOtpService,
        private OtpExceptionHttpMapper $otpExceptionHttpMapper,
        private AuthOtpAuditRecorder $authOtpAudit,
    ) {}

    public function requestCode(LoginRequestCodeRequest $request): JsonResponse
    {
        if (AkubicaLoginOtpService::isEnabled()) {
            return $this->requestCodeP0a($request);
        }

        return $this->requestCodeLegacy($request);
    }

    public function verifyCode(LoginVerifyCodeRequest $request): JsonResponse
    {
        if (AkubicaLoginOtpService::isEnabled()) {
            return $this->verifyCodeP0a($request);
        }

        return $this->verifyCodeLegacy($request);
    }

    public function resendCode(LoginOtpResendRequest $request): JsonResponse
    {
        if (! AkubicaLoginOtpService::isEnabled()) {
            return ApiResponse::error(
                'FEATURE_DISABLED',
                'El reenvio OTP P0-A no esta habilitado.',
                503,
            );
        }

        $challengePublicId = (string) $request->validated('challenge_id');
        $material = $this->loginMaterialFromChallenge($challengePublicId);

        try {
            $this->akubicaLoginOtpService->assertConfigurationReady();
            $payload = $this->akubicaLoginOtpService->resend(
                $challengePublicId,
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            $response = $this->otpExceptionHttpMapper->toResponse($e);
            $classified = $this->authOtpAudit->classifyErrorResponse($response);
            $this->authOtpAudit->recordLoginCodeRequested(
                request: $request,
                phoneComparisonKey: $material,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                challengePublicId: $challengePublicId,
                isDecoy: false,
                isResend: true,
            );

            return $response;
        }

        $isDecoy = $this->isDecoyChallengePayload($payload);
        $this->authOtpAudit->recordLoginCodeRequested(
            request: $request,
            phoneComparisonKey: $material,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 202,
            challengePublicId: is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : $challengePublicId,
            isDecoy: $isDecoy,
            isResend: true,
        );

        return ApiResponse::success($payload, null, 202);
    }

    private function requestCodeLegacy(LoginRequestCodeRequest $request): JsonResponse
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

    private function requestCodeP0a(LoginRequestCodeRequest $request): JsonResponse
    {
        $phoneComparisonKey = null;

        try {
            $this->akubicaLoginOtpService->assertConfigurationReady();

            $phone = $this->akubicaLoginOtpService->normalizePhone(
                (string) $request->validated('phone'),
                $request->validated('phone_country') ?? null,
            );
            $phoneComparisonKey = $phone->comparisonKey();

            $user = $this->akubicaLoginOtpService->findEligibleUser($phone);

            if ($user === null) {
                $payload = $this->akubicaLoginOtpService->decoyRequestResponse($phone);
                $this->authOtpAudit->recordLoginCodeRequested(
                    request: $request,
                    phoneComparisonKey: $phoneComparisonKey,
                    outcome: AuditOutcome::SUCCEEDED,
                    httpStatus: 202,
                    challengePublicId: is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : null,
                    isDecoy: true,
                    isResend: false,
                );

                return ApiResponse::success($payload, null, 202);
            }

            $payload = $this->akubicaLoginOtpService->request($user, $phone, $request->ip());
        } catch (OtpIdentityNormalizationException $e) {
            return ApiResponse::error(
                'VALIDATION_ERROR',
                'Los datos de inicio de sesion no son validos.',
                422,
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            $response = $this->otpExceptionHttpMapper->toResponse($e);
            if (is_string($phoneComparisonKey) && $phoneComparisonKey !== '') {
                $classified = $this->authOtpAudit->classifyErrorResponse($response);
                $this->authOtpAudit->recordLoginCodeRequested(
                    request: $request,
                    phoneComparisonKey: $phoneComparisonKey,
                    outcome: $classified['outcome'],
                    httpStatus: $classified['http_status'],
                    errorCode: $classified['error_code'],
                    isDecoy: false,
                    isResend: false,
                );
            }

            return $response;
        }

        $this->authOtpAudit->recordLoginCodeRequested(
            request: $request,
            phoneComparisonKey: $phoneComparisonKey,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 202,
            challengePublicId: is_string($payload['challenge_id'] ?? null) ? $payload['challenge_id'] : null,
            isDecoy: false,
            isResend: false,
        );

        return ApiResponse::success($payload, null, 202);
    }

    private function verifyCodeLegacy(LoginVerifyCodeRequest $request): JsonResponse
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

    private function verifyCodeP0a(LoginVerifyCodeRequest $request): JsonResponse
    {
        $challengePublicId = (string) $request->validated('challenge_id');
        $material = $this->loginMaterialFromChallenge($challengePublicId);

        try {
            $this->akubicaLoginOtpService->assertConfigurationReady();
            $payload = $this->akubicaLoginOtpService->verify(
                $challengePublicId,
                $request->validated('code'),
                $request->ip(),
            );
        } catch (OtpConfigurationException|OtpChallengeException $e) {
            $response = $this->otpExceptionHttpMapper->toResponse($e);
            $classified = $this->authOtpAudit->classifyErrorResponse($response);
            $this->authOtpAudit->recordLoginVerified(
                request: $request,
                phoneComparisonKey: $material,
                outcome: $classified['outcome'],
                httpStatus: $classified['http_status'],
                errorCode: $classified['error_code'],
                challengePublicId: $challengePublicId,
                markTerminal: true,
            );

            return $response;
        }

        $userId = is_array($payload['user'] ?? null) ? ($payload['user']['id'] ?? null) : null;
        $user = is_numeric($userId) ? User::query()->find((int) $userId) : null;

        $this->authOtpAudit->recordLoginVerified(
            request: $request,
            phoneComparisonKey: $material,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            authenticatedUser: $user,
            challengePublicId: $challengePublicId,
            markTerminal: true,
        );

        return ApiResponse::success($payload);
    }

    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'name' => trim($user->full_name) ?: $user->name,
        ];
    }

    /**
     * Normalized phone comparison key from challenge, or opaque HMAC material for decoys.
     */
    private function loginMaterialFromChallenge(string $challengePublicId): string
    {
        $subjectKey = OtpChallenge::query()
            ->where('public_id', $challengePublicId)
            ->value('subject_key');

        if (is_string($subjectKey) && $subjectKey !== '') {
            return $subjectKey;
        }

        // Decoy / unknown: material is HMAC'd — public id never persisted as actor_key.
        return 'login-challenge-ref|'.$challengePublicId;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function isDecoyChallengePayload(array $payload): bool
    {
        $publicId = $payload['challenge_id'] ?? null;
        if (! is_string($publicId) || $publicId === '') {
            return true;
        }

        return ! OtpChallenge::query()->where('public_id', $publicId)->exists();
    }
}
