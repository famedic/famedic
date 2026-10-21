<?php

namespace App\Services\Api\V1\Integrations;

use App\Http\Responses\ApiResponse;
use App\Models\OtpChallenge;
use App\Services\Api\V1\Auth\AccountContextTokenService;
use App\Services\Api\V1\Auth\ContactRecognitionContext;
use App\Services\Otp\AkubicaLoginOtpService;
use Illuminate\Http\JsonResponse;

final class AtixPhoneLookupService
{
    public function __construct(
        private readonly AkubicaLoginOtpService $otp,
        private readonly AccountContextTokenService $contexts,
    ) {}

    public function lookup(array $data): JsonResponse
    {
        try {
            $phone = $this->otp->normalizePhone($data['phone'], (string) config('atix.sandbox.phone_country', 'MX'));
        } catch (\Throwable) {
            return ApiResponse::error('VALIDATION_ERROR', 'El telefono no es valido.', 422, ['phone' => ['Formato E.164 requerido.']], null, false);
        }

        $user = $this->otp->findEligibleUser($phone);
        if ($user === null) {
            return ApiResponse::success($this->otp->decoyRequestResponse($phone), null, 202);
        }

        $context = new ContactRecognitionContext(
            (string) $data['conversation_id'],
            (string) $data['channel_event_id'],
            (string) $data['channel'],
            (bool) data_get($data, 'personalization.allowed', false),
            (string) $data['audience'],
        );
        $payload = $this->otp->request($user, $phone, request()->ip(), $context);
        $challenge = OtpChallenge::query()->where('public_id', $payload['challenge_id'])->firstOrFail();
        $this->contexts->issuePreOtp(
            $user,
            hash_hmac('sha256', $phone->comparisonKey(), config('app.key')),
            $context->binding(config('app.key')),
            $challenge->id,
        );

        if ($context->isPersonalizationAllowed()) {
            $payload['personalization'] = [
                'allowed' => true,
                'first_name' => $this->firstName($user),
            ];
        } else {
            $payload['personalization'] = ['allowed' => false];
        }

        return ApiResponse::success($payload, null, 202);
    }

    private function firstName(object $user): ?string
    {
        $name = trim((string) ($user->name ?? ''));
        if ($name === '') return null;
        return preg_split('/\s+/', $name)[0] ?? null;
    }
}
