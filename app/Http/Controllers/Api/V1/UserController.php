<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AddressResource;
use App\Http\Resources\Api\V1\FamilyMemberResource;
use App\Http\Resources\Api\V1\PaymentMethodResource;
use App\Http\Resources\Api\V1\TaxProfileResource;
use App\Http\Responses\ApiResponse;
use App\Models\EfevooToken;
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

        return ApiResponse::success([
            'family' => FamilyMemberResource::collection($familyAccounts)->resolve($request),
        ]);
    }

    public function taxProfiles(Request $request): JsonResponse
    {
        $taxProfiles = $request->user()->customer
            ->taxProfiles()
            ->orderBy('id')
            ->get();

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
