<?php

namespace App\Models;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Enums\InvoiceRequestWorkflowStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvoiceRequestStatusLog extends Model
{
    protected $fillable = [
        'invoice_request_id',
        'from_status',
        'to_status',
        'trigger',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'from_status' => InvoiceRequestWorkflowStatus::class,
            'to_status' => InvoiceRequestWorkflowStatus::class,
            'trigger' => InvoiceRequestStatusLogTrigger::class,
            'metadata' => 'array',
        ];
    }

    public function invoiceRequest(): BelongsTo
    {
        return $this->belongsTo(InvoiceRequest::class);
    }
}
