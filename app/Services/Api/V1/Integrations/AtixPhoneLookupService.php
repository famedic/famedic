<?php

namespace App\Services\Api\V1\Integrations;

use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Services\Api\V1\Auth\AccountContextTokenService;
use App\Services\Api\V1\Auth\ContactRecognitionContext;
use App\Services\Otp\AkubicaLoginOtpService;
use App\Support\Api\V1\AtixPhoneLookupRecognitionPayload;
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

        $context = new ContactRecognitionContext(
            (string) $data['conversation_id'],
            (string) $data['channel_event_id'],
            strtolower(trim((string) $data['channel'])),
            (bool) data_get($data, 'personalization.allowed', false),
            (string) $data['audience'],
        );

        $user = $this->otp->findEligibleUser($phone);
        if (! $user instanceof User) {
            return ApiResponse::success(AtixPhoneLookupRecognitionPayload::unknown(), null, 202);
        }

        $phoneHash = hash_hmac('sha256', $phone->comparisonKey(), (string) config('app.key'));
        $binding = $context->binding((string) config('app.key'));

        $this->contexts->ensurePreOtpContext($user, $phoneHash, $binding);

        return ApiResponse::success(
            AtixPhoneLookupRecognitionPayload::recognized($user, $context->isPersonalizationAllowed()),
            null,
            202,
        );
    }
}
