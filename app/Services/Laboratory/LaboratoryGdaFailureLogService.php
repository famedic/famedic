<?php

namespace App\Services\Laboratory;

use App\Enums\LaboratoryGdaFailureOperation;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Models\LaboratoryGdaFailureLog;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Support\GDA\GdaApiUrl;
use Throwable;

class LaboratoryGdaFailureLogService
{
    public function recordUncertain(
        GdaOrderResultUncertainException $exception,
        LaboratoryGdaFailureOperation $operation,
        ?User $administrator = null,
        ?LaboratoryPurchase $purchase = null,
        ?int $sourceLaboratoryPurchaseId = null,
    ): LaboratoryGdaFailureLog {
        $context = $exception->context();
        $summary = $context['response_summary'] ?? [];
        $purchaseId = $context['laboratory_purchase_id'] ?? $purchase?->id;

        $purchase ??= $purchaseId
            ? LaboratoryPurchase::query()->with(['customer', 'laboratoryPurchaseItems'])->find($purchaseId)
            : null;

        return LaboratoryGdaFailureLog::query()->create([
            'operation' => $operation,
            'laboratory_purchase_id' => $purchase?->id ?? $purchaseId,
            'source_laboratory_purchase_id' => $sourceLaboratoryPurchaseId,
            'customer_id' => $purchase?->customer_id,
            'administrator_user_id' => $administrator?->id,
            'brand' => $purchase?->brand,
            'failure_reason' => $exception->reason(),
            'gda_code_http' => isset($summary['gda_code_http']) ? (string) $summary['gda_code_http'] : null,
            'gda_mensaje' => isset($summary['gda_mensaje']) ? (string) $summary['gda_mensaje'] : null,
            'gda_description' => isset($summary['gda_description']) ? (string) $summary['gda_description'] : null,
            'http_status' => $exception->httpStatus() ?? ($context['http_status'] ?? null),
            'requisition_value' => $purchaseId ? (string) $purchaseId : null,
            'message' => $this->buildMessage($exception, $summary),
            'response_summary' => is_array($summary) ? $summary : null,
            'context' => $this->buildContext($purchase, $exception),
        ]);
    }

    public function recordValidationFailure(
        string $message,
        LaboratoryGdaFailureOperation $operation,
        LaboratoryPurchase $purchase,
        ?User $administrator = null,
        ?Throwable $previous = null,
    ): LaboratoryGdaFailureLog {
        return LaboratoryGdaFailureLog::query()->create([
            'operation' => $operation,
            'laboratory_purchase_id' => $purchase->id,
            'source_laboratory_purchase_id' => $operation === LaboratoryGdaFailureOperation::AdminReplace
                ? $purchase->id
                : null,
            'customer_id' => $purchase->customer_id,
            'administrator_user_id' => $administrator?->id,
            'brand' => $purchase->brand,
            'failure_reason' => 'admin_validation_failed',
            'requisition_value' => (string) $purchase->id,
            'message' => $message,
            'context' => array_filter([
                'exception_class' => $previous ? $previous::class : null,
                'gda_api_url' => GdaApiUrl::endpoint('service-request'),
                'app_env' => config('app.env'),
                'items' => $purchase->relationLoaded('laboratoryPurchaseItems')
                    ? $purchase->laboratoryPurchaseItems->map(fn ($item) => [
                        'gda_id' => $item->gda_id,
                        'name' => $item->name,
                    ])->values()->all()
                    : null,
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function buildMessage(GdaOrderResultUncertainException $exception, array $summary): string
    {
        $parts = array_filter([
            $exception->getMessage(),
            isset($summary['gda_description']) ? (string) $summary['gda_description'] : null,
            isset($summary['gda_mensaje']) ? 'GDA: '.$summary['gda_mensaje'] : null,
            isset($summary['gda_code_http']) ? 'codeHttp '.$summary['gda_code_http'] : null,
            $exception->reason() !== 'unknown' ? '('.$exception->reason().')' : null,
        ]);

        return implode(' · ', $parts);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(
        ?LaboratoryPurchase $purchase,
        GdaOrderResultUncertainException $exception,
    ): array {
        return array_filter([
            'gda_api_url' => GdaApiUrl::endpoint('service-request'),
            'app_env' => config('app.env'),
            'exception_class' => $exception::class,
            'items' => $purchase && $purchase->relationLoaded('laboratoryPurchaseItems')
                ? $purchase->laboratoryPurchaseItems->map(fn ($item) => [
                    'gda_id' => $item->gda_id,
                    'name' => $item->name,
                    'price_cents' => $item->price_cents,
                ])->values()->all()
                : null,
        ]);
    }
}
