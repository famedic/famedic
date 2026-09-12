<?php

namespace App\Actions\Marketing;

use App\DataTransferObjects\Marketing\RecordMarketingCampaignConversionResult;
use App\Enums\MarketingCampaignConversionStatus;
use App\Models\LaboratoryPurchase;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignVisit;
use App\Models\Transaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordMarketingCampaignConversionAction
{
    public function __invoke(LaboratoryPurchase $purchase): RecordMarketingCampaignConversionResult
    {
        try {
            return DB::transaction(function () use ($purchase): RecordMarketingCampaignConversionResult {
                $existing = $this->existingConversion($purchase);

                if ($existing !== null) {
                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::AlreadyRecorded,
                        $existing,
                    );
                }

                $purchase->loadMissing(['customer.user', 'transactions']);

                $successfulTransactions = $this->successfulTransactions($purchase);

                if ($successfulTransactions->isEmpty()) {
                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::InvalidAttribution,
                    );
                }

                $convertedAt = $purchase->created_at ?? now();
                $customerId = $purchase->customer_id !== null ? (int) $purchase->customer_id : null;
                $userId = $purchase->customer?->user_id !== null ? (int) $purchase->customer->user_id : null;

                if ($customerId === null) {
                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::InvalidAttribution,
                    );
                }

                $candidates = $this->activeAttributionsForPurchase($customerId, $convertedAt);

                if ($candidates->isEmpty()) {
                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::NotAttributed,
                    );
                }

                $attribution = $candidates->first();

                if ($candidates->count() > 1) {
                    Log::warning('marketing_campaign_conversion_multiple_active_attributions', [
                        'purchase_id' => $purchase->id,
                        'customer_id' => $customerId,
                        'selected_marketing_campaign_attribution_id' => $attribution->id,
                        'active_attributions_count' => $candidates->count(),
                    ]);
                }

                if ($this->hasCustomerConflict($attribution, $customerId, $userId)) {
                    Log::warning('marketing_campaign_conversion_invalid_attribution', [
                        'purchase_id' => $purchase->id,
                        'customer_id' => $customerId,
                        'marketing_campaign_attribution_id' => $attribution->id,
                        'reason' => 'customer_conflict',
                    ]);

                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::InvalidAttribution,
                    );
                }

                $attribution->loadMissing(['firstVisit', 'lastVisit']);
                $lastVisit = $attribution->lastVisit;

                if ($lastVisit === null || $attribution->last_visit_id === null) {
                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::InvalidAttribution,
                    );
                }

                $amountCents = $successfulTransactions->sum(
                    fn (Transaction $transaction): int => $this->capturedAmountCents($transaction),
                );

                try {
                    $conversion = MarketingCampaignConversion::query()->create([
                        'marketing_campaign_attribution_id' => $attribution->id,
                        'marketing_campaign_visitor_identity_id' => $attribution->marketing_campaign_visitor_identity_id,
                        'first_campaign_id' => $attribution->first_campaign_id,
                        'first_link_id' => $attribution->first_link_id,
                        'first_visit_id' => $attribution->first_visit_id,
                        'last_campaign_id' => $attribution->last_campaign_id,
                        'last_link_id' => $attribution->last_link_id,
                        'last_visit_id' => $attribution->last_visit_id,
                        'user_id' => $userId,
                        'customer_id' => $customerId,
                        'conversion_type' => MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE,
                        'purchase_id' => $purchase->id,
                        'currency' => 'MXN',
                        'amount_cents' => $amountCents,
                        'utm_source' => $lastVisit->utm_source,
                        'utm_medium' => $lastVisit->utm_medium,
                        'utm_campaign' => $lastVisit->utm_campaign,
                        'utm_term' => $lastVisit->utm_term,
                        'utm_content' => $lastVisit->utm_content,
                        'gclid' => $lastVisit->gclid,
                        'fbclid' => $lastVisit->fbclid,
                        'converted_at' => $convertedAt,
                        'created_at' => now(),
                    ]);
                } catch (QueryException $e) {
                    if (! $this->isUniqueConstraintViolation($e)) {
                        throw $e;
                    }

                    $existing = $this->existingConversion($purchase);

                    if ($existing === null) {
                        throw $e;
                    }

                    return new RecordMarketingCampaignConversionResult(
                        MarketingCampaignConversionStatus::AlreadyRecorded,
                        $existing,
                    );
                }

                return new RecordMarketingCampaignConversionResult(
                    MarketingCampaignConversionStatus::Created,
                    $conversion,
                );
            });
        } catch (Throwable $e) {
            Log::error('marketing_campaign_conversion_record_failed', [
                'purchase_id' => $purchase->id,
                'customer_id' => $purchase->customer_id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return new RecordMarketingCampaignConversionResult(
                MarketingCampaignConversionStatus::Failed,
            );
        }
    }

    private function existingConversion(LaboratoryPurchase $purchase): ?MarketingCampaignConversion
    {
        return MarketingCampaignConversion::query()
            ->where('conversion_type', MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE)
            ->where('purchase_id', $purchase->id)
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, MarketingCampaignAttribution>
     */
    private function activeAttributionsForPurchase(int $customerId, \DateTimeInterface $convertedAt)
    {
        return MarketingCampaignAttribution::query()
            ->where('expires_at', '>', $convertedAt)
            ->where('first_touched_at', '<=', $convertedAt)
            ->where('last_touched_at', '<=', $convertedAt)
            ->where(function ($query) use ($customerId) {
                $query->where('customer_id', $customerId)
                    ->orWhereHas('visits', fn ($visits) => $visits->where('customer_id', $customerId));
            })
            ->orderByDesc('last_touched_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();
    }

    private function hasCustomerConflict(
        MarketingCampaignAttribution $attribution,
        int $customerId,
        ?int $userId,
    ): bool {
        if ($attribution->customer_id !== null && (int) $attribution->customer_id !== $customerId) {
            return true;
        }

        if ($userId !== null && $attribution->user_id !== null && (int) $attribution->user_id !== $userId) {
            return true;
        }

        return MarketingCampaignVisit::query()
            ->where('marketing_campaign_attribution_id', $attribution->id)
            ->where(function ($query) use ($customerId, $userId) {
                $query->where(function ($query) use ($customerId) {
                    $query->whereNotNull('customer_id')
                        ->where('customer_id', '!=', $customerId);
                });

                if ($userId !== null) {
                    $query->orWhere(function ($query) use ($userId) {
                        $query->whereNotNull('user_id')
                            ->where('user_id', '!=', $userId);
                    });
                }
            })
            ->exists();
    }

    /**
     * @return \Illuminate\Support\Collection<int, Transaction>
     */
    private function successfulTransactions(LaboratoryPurchase $purchase)
    {
        return $purchase->transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->isSuccessfulPayment())
            ->values();
    }

    private function capturedAmountCents(Transaction $transaction): int
    {
        if ($transaction->transaction_amount_cents !== null) {
            return max(0, (int) $transaction->transaction_amount_cents);
        }

        return max(0, (int) data_get($transaction->details, 'amount_charged_cents', 0));
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return in_array($sqlState, ['23000', '23505'], true)
            || in_array($driverCode, ['1062', '1555', '2067'], true);
    }
}
