<?php

namespace App\Models;

use App\Enums\InvoiceRequestWorkflowStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InvoiceRequest extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * PF-1B.1: permanece abierto a nivel modelo.
     * No hay FormRequest/HTTP que haga mass-assignment de tax_profile_id;
     * CreateInvoiceRequestAction escribe campos explícitos del snapshot.
     * PF-1B.3 deberá setear tax_profile_id solo vía Action (no desde request).
     */
    protected $guarded = [];

    protected $appends = [
        'formatted_tax_regime',
        'formatted_cfdi_use',
        'formatted_created_at',
        'formatted_submitted_to_billing_at',
        'formatted_sample_completed_at',
    ];

    protected function casts(): array
    {
        return [
            'workflow_status' => InvoiceRequestWorkflowStatus::class,
            'submitted_to_billing_at' => 'datetime',
            'sample_completed_at' => 'datetime',
            'billing_team_notified_at' => 'datetime',
        ];
    }

    public function scopeForActiveLaboratoryPurchases(Builder $query): Builder
    {
        return $query
            ->where('invoice_requestable_type', LaboratoryPurchase::class)
            ->whereHasMorph('invoiceRequestable', [LaboratoryPurchase::class]);
    }

    public function invoiceRequestable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Conserva el vínculo histórico aunque el perfil esté soft-deleted.
     */
    public function taxProfile(): BelongsTo
    {
        return $this->belongsTo(TaxProfile::class)->withTrashed();
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(InvoiceRequestStatusLog::class);
    }

    public function isAwaitingSampleCollection(): bool
    {
        return $this->workflow_status === InvoiceRequestWorkflowStatus::AwaitingSampleCollection;
    }

    public function isSubmittedToBilling(): bool
    {
        return $this->workflow_status === InvoiceRequestWorkflowStatus::SubmittedToBilling;
    }

    public function isCancelled(): bool
    {
        return $this->workflow_status === InvoiceRequestWorkflowStatus::Cancelled;
    }

    protected function formattedTaxRegime(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->tax_regime) {
                    return null;
                }

                $regime = config('taxregimes.regimes.'.$this->tax_regime);

                return is_array($regime)
                    ? $this->tax_regime.' - '.($regime['name'] ?? $this->tax_regime)
                    : (string) $this->tax_regime;
            }
        );
    }

    protected function formattedCfdiUse(): Attribute
    {
        return Attribute::make(
            get: function () {
                if (! $this->cfdi_use) {
                    return null;
                }

                $use = config('taxregimes.uses.'.$this->cfdi_use);

                return $use
                    ? $this->cfdi_use.' - '.$use
                    : (string) $this->cfdi_use;
            }
        );
    }

    protected function formattedCreatedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->created_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedSubmittedToBillingAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->submitted_to_billing_at)?->isoFormat('D MMM Y h:mm a')
        );
    }

    protected function formattedSampleCompletedAt(): Attribute
    {
        return Attribute::make(
            get: fn () => localizedDate($this->sample_completed_at)?->isoFormat('D MMM Y h:mm a')
        );
    }
}
