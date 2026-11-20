<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Resources\Api\V1\FamilyMemberResource;
use App\Http\Resources\Api\V1\Leo\LeoFamilyMemberResource;
use App\Http\Resources\Api\V1\Leo\LeoTaxProfileSummaryResource;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Http\Resources\Api\V1\TaxProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\EfevooToken;
use App\Support\Api\V1\LeoConsumer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function family(Request $request): JsonResponse
    {
        $familyAccounts = $request->user()->customer
            ->familyAccounts()
            ->orderBy('id')
            ->get();

        // rev7 L08: Leo receives LeoFamilyMember only; native keeps its resource unchanged.
        $resource = LeoConsumer::is($request) ? LeoFamilyMemberResource::class : FamilyMemberResource::class;

        return ApiResponse::success([
            'family' => $resource::collection($familyAccounts)->resolve($request),
        ]);
    }

    public function taxProfiles(Request $request): JsonResponse
    {
        $taxProfiles = $request->user()->customer
            ->taxProfiles()
            ->orderBy('id')
            ->get();

        // rev7 D-1/L13b: Leo receives the masked LeoTaxProfileSummary only; native keeps its resource unchanged.
        if (LeoConsumer::is($request)) {
            return ApiResponse::success([
                'tax_profiles' => LeoTaxProfileSummaryResource::collection(
                    $taxProfiles->filter(fn ($profile) => LeoTaxProfileSummaryResource::presentable($profile))->values()
                )->resolve($request),
            ]);
        }

        return ApiResponse::success([
            'tax_profiles' => TaxProfileResource::collection($taxProfiles)->resolve($request),
        ]);
    }

    public function addresses(Request $request): JsonResponse
    {
        $addresses = $request->user()->customer
            ->addresses()
            ->orderBy('id')
            ->get();

        return ApiResponse::success([
            'addresses' => AddressResource::collection($addresses)->resolve($request),
        ]);
    }

    public function paymentMethods(Request $request): JsonResponse
    {
        $customerId = $request->user()->customer->id;

        $tokens = EfevooToken::query()
            ->where('customer_id', $customerId)
            ->active()
            ->excludeMockInProduction()
            ->orderByDesc('created_at')
            ->get()
            ->unique(fn (EfevooToken $token) => $token->card_last_four.'-'.($token->card_expiration ?? ''))
            ->values();

        return ApiResponse::success([
            'payment_methods' => PaymentMethodResource::collection($tokens)->resolve($request),
        ]);
    }
}
