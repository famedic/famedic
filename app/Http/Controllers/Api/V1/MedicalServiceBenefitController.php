<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\MedicalServiceBenefitException;
use App\Http\Requests\Api\V1\Cart\ApplyMedicalServiceBenefitRequest;
use App\Http\Requests\Api\V1\Cart\GetMedicalServiceBenefitRequest;
use App\Http\Requests\Api\V1\Cart\RemoveMedicalServiceBenefitRequest;
use App\Http\Resources\Api\V1\MedicalServiceCartResource;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\MedicalServiceCartBenefitService;
use App\Services\Api\V1\MedicalServiceCartService;
use App\Services\Api\V1\Audit\AuditOutcome;
use App\Services\Api\V1\Audit\MedicalServiceCartAuditRecorder;
use Illuminate\Http\JsonResponse;

class MedicalServiceBenefitController
{
    public function __construct(
        private readonly MedicalServiceCartBenefitService $benefitService,
        private readonly MedicalServiceCartService $cartService,
        private readonly MedicalServiceCartAuditRecorder $audit,
    ) {}

    public function show(GetMedicalServiceBenefitRequest $request): JsonResponse
    {
        $cart = $this->cartService->activeCart($request->user()->customer);

        return ApiResponse::success([
            'service_line' => 'medical_services',
            'cart' => (new MedicalServiceCartResource($cart))->resolve($request),
        ]);
    }

    public function apply(ApplyMedicalServiceBenefitRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;

        try {
            $cart = $this->benefitService->apply($customer, $request->user(), $request->validated('code'));
        } catch (MedicalServiceBenefitException $exception) {
            $status = match ($exception->errorCode) {
                'COUPON_NOT_FOUND' => 404,
                default => 409,
            };
            $response = ApiResponse::error($exception->errorCode, $exception->getMessage(), $status);
            $classified = $this->audit->classifyErrorResponse($response);
            $this->audit->recordApplied($request, $classified['outcome'], $status, $classified['error_code'], $customer->id);

            return $response;
        }

        $this->audit->recordApplied(
            request: $request,
            outcome: AuditOutcome::SUCCEEDED,
            httpStatus: 200,
            customerId: (int) $customer->id,
            cart: $cart,
        );

        return ApiResponse::success([
            'service_line' => 'medical_services',
            'cart' => (new MedicalServiceCartResource($cart))->resolve($request),
        ]);
    }

    public function remove(RemoveMedicalServiceBenefitRequest $request): JsonResponse
    {
        $customer = $request->user()->customer;
        $result = $this->benefitService->remove($customer);
        $cart = $result['cart'];

        if ($result['removed']) {
            $this->audit->recordRemoved(
                request: $request,
                outcome: AuditOutcome::SUCCEEDED,
                httpStatus: 200,
                customerId: (int) $customer->id,
                cart: $cart,
                removedAmountCents: (int) $result['removed_amount_cents'],
                removedBenefitType: $result['removed_benefit_type'],
                removedCouponId: $result['removed_coupon_id'],
            );
        }

        return ApiResponse::success([
            'service_line' => 'medical_services',
            'removed' => (bool) $result['removed'],
            'cart' => (new MedicalServiceCartResource($cart))->resolve($request),
        ]);
    }
}
