<?php

namespace App\Services\MedicalCheckout;

use App\Models\Transaction;
use App\Services\Api\V1\Benefits\MedicalPaymentAuthority;
use App\Services\Api\V1\Benefits\ReservationReleaseAuthority;
use Illuminate\Support\Facades\DB;

/** Only server-side verified provider observations or explicit internal funding create these records. */
final class RecordedMedicalPaymentAuthority implements MedicalPaymentAuthority, ReservationReleaseAuthority
{
    public static function hasIntent(Transaction $p, int $customer): bool
    {
        return DB::table('medical_purchase_intents')->where('transaction_id', $p->id)->where('customer_id', $customer)->whereIn('state', ['confirmed', 'debited', 'fulfilled', 'reversing', 'reversed'])->exists();
    }

    private function matches(Transaction $p, int $customer, string $kind, ?int $cash = null): bool
    {
        return DB::table('medical_purchase_intents as i')->join('medical_payment_verifications as v', 'v.intent_id', '=', 'i.id')->where('i.transaction_id', $p->id)->where('i.customer_id', $customer)->where('v.kind', $kind)->whereColumn('v.cash_cents', 'i.cash_cents')->where('v.cash_cents', $cash ?? (int) $p->transaction_amount_cents)->where('v.currency', 'MXN')->whereColumn('v.provider', 'i.provider')->exists();
    }

    public function confirmed(Transaction $p, int $customer, int $cash): bool
    {
        return self::hasIntent($p, $customer) && $this->matches($p, $customer, 'confirmed', $cash);
    }

    public function reversed(Transaction $p, int $customer): bool
    {
        return self::hasIntent($p, $customer) && $this->matches($p, $customer, 'reversed');
    }

    public function definitivelyUnpaid(Transaction $p, int $customer): bool
    {
        return $this->matches($p,$customer,'unpaid');
    }
}
