<?php

namespace App\Http\Controllers\WebHook;

use App\Http\Controllers\Controller;
use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptProcessor;
use App\Services\Otp\Delivery\VonageSmsDeliveryReceiptValidator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Vonage SMS API delivery receipt (DLR) webhook.
 *
 * NOT Messages API — Akúbica OTP uses $client->sms()->send() (SMS REST API).
 */
final class VonageSmsDeliveryReceiptController extends Controller
{
    public function __construct(
        private readonly VonageSmsDeliveryReceiptValidator $validator,
        private readonly VonageSmsDeliveryReceiptProcessor $processor,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        if (! $this->validator->enabled()) {
            abort(SymfonyResponse::HTTP_NOT_FOUND);
        }

        if (! $this->validator->validateRouteToken($token)) {
            abort(SymfonyResponse::HTTP_FORBIDDEN);
        }

        $params = $this->validator->extractParams($request);

        if (! $this->validator->validateSignature($params)) {
            abort(SymfonyResponse::HTTP_FORBIDDEN);
        }

        $this->processor->process($params);

        return response('', SymfonyResponse::HTTP_OK);
    }
}
