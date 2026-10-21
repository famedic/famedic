<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\MembershipPlanCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MembershipController
{
    public function plans(MembershipPlanCatalogService $catalog): JsonResponse
    {
        return ApiResponse::success(['plans' => $catalog->plans()]);
    }

    public function status(Request $request, MembershipPlanCatalogService $catalog): JsonResponse
    {
        return ApiResponse::success($catalog->status($request->user()->customer));
    }
}
