<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\Benefits\BenefitMutationDto;
use App\Http\Resources\Api\V1\Benefits\BenefitSnapshotDto;
use App\Http\Responses\ApiResponse;
use App\Services\Api\V1\Benefits\BenefitErrorResponse;
use App\Services\Api\V1\Benefits\LaravelOpaqueBenefits;
use Illuminate\Http\Request;

final class OpaqueBenefitController
{
    public function __construct(private LaravelOpaqueBenefits $benefits) {}

    public function index(Request $r)
    {
        try {
            $response = ApiResponse::success(BenefitSnapshotDto::from($this->benefits->snapshot($r)));
            $response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (\Throwable $e) {
            return BenefitErrorResponse::make($e);
        }
    }

    public function apply(Request $r)
    {
        return $this->change($r, 'apply');
    }

    public function remove(Request $r, string $application_ref)
    {
        return $this->change($r, 'remove', $application_ref);
    }

    private function change(Request $r, string $op, ?string $ref = null)
    {
        try {
            $data = $this->benefits->mutate($r, $op, $ref);
            $response = ApiResponse::success(BenefitMutationDto::from($data), null, $op === 'apply' ? 201 : 200);
            if ($data['replayed']) {
                $response->headers->set('Idempotency-Replayed', 'true');
            }$response->headers->set('Cache-Control', 'private, no-store');

            return $response;
        } catch (\Throwable $e) {
            return BenefitErrorResponse::make($e);
        }
    }
}
