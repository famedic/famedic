<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LaboratoryPurchase;
use App\Support\Api\V1\LaboratoryOrderResults;
use App\Support\Api\V1\LaboratoryOrderStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\LaboratoryNotification */
class OrderResultResource extends JsonResource
{
    public function __construct(
        $resource,
        protected LaboratoryPurchase $order,
    ) {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => LaboratoryOrderStatus::formatStudyName($this->order),
            'available_at' => $this->results_received_at?->toIso8601String(),
            'download_url' => LaboratoryOrderResults::apiDownloadUrl($this->order),
            'has_pdf' => ! empty($this->results_pdf_base64),
        ];
    }
}
