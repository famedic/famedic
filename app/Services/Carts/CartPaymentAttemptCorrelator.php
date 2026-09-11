<?php

namespace App\Services\Carts;

use App\Models\Cart;
use App\Models\PaymentAttempt;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class CartPaymentAttemptCorrelator
{
    private const GATEWAY = 'efevoopay';

    private const AMOUNT_TOLERANCE_CENTS = 100;

    private const WINDOW_START_TOLERANCE_MINUTES = 5;

    private const WINDOW_END_TOLERANCE_HOURS = 2;

    /**
     * @param  Collection<int, Cart>  $carts
     * @return array<int, array<string, mixed>>
     */
    public function forCarts(Collection $carts): array
    {
        $eligibleCarts = $carts
            ->filter(fn (Cart $cart) => $cart->user?->customer?->id && $cart->created_at && $cart->updated_at)
            ->values();

        if ($eligibleCarts->isEmpty()) {
            return [];
        }

        $hasExplicitCartId = Schema::hasColumn('payment_attempts', 'cart_id');
        $insights = [];

        if ($hasExplicitCartId) {
            $explicitAttempts = PaymentAttempt::query()
                ->select([
                    'id',
                    'customer_id',
                    'cart_id',
                    'amount_cents',
                    'gateway',
                    'reference',
                    'status',
                    'processor_code',
                    'processor_message',
                    'processed_at',
                    'created_at',
                    'updated_at',
                ])
                ->where('gateway', self::GATEWAY)
                ->whereIn('cart_id', $eligibleCarts->pluck('id')->filter()->values())
                ->orderBy('created_at')
                ->get()
                ->groupBy(fn (PaymentAttempt $attempt) => (int) $attempt->cart_id);

            foreach ($explicitAttempts as $cartId => $attempts) {
                $latest = $attempts
                    ->sortByDesc(fn (PaymentAttempt $attempt) => $this->attemptTimestamp($attempt)?->timestamp ?? 0)
                    ->first();

                if ($latest) {
                    $insights[(int) $cartId] = $this->serializeInsight($latest, $attempts->values(), 'explicit');
                }
            }
        }

        $legacyEligibleCarts = $eligibleCarts
            ->reject(fn (Cart $cart) => array_key_exists((int) $cart->id, $insights))
            ->values();

        if ($legacyEligibleCarts->isEmpty()) {
            return $insights;
        }

        $customerIds = $eligibleCarts
            ->map(fn (Cart $cart) => (int) $cart->user->customer->id)
            ->unique()
            ->values();
        $userIds = $eligibleCarts
            ->pluck('user_id')
            ->filter()
            ->unique()
            ->values();

        $minCreatedAt = $eligibleCarts
            ->min(fn (Cart $cart) => $cart->created_at->timestamp);
        $maxUpdatedAt = $eligibleCarts
            ->max(fn (Cart $cart) => ($cart->completed_at ?? $cart->updated_at)->timestamp);

        $attempts = PaymentAttempt::query()
            ->select([
                'id',
                'customer_id',
                'amount_cents',
                'gateway',
                'reference',
                'status',
                'processor_code',
                'processor_message',
                'processed_at',
                'created_at',
                'updated_at',
            ])
            ->where('gateway', self::GATEWAY)
            ->whereIn('customer_id', $customerIds)
            ->whereBetween('created_at', [
                now()->setTimestamp($minCreatedAt)->subMinutes(self::WINDOW_START_TOLERANCE_MINUTES),
                now()->setTimestamp($maxUpdatedAt)->addHours(self::WINDOW_END_TOLERANCE_HOURS),
            ])
            ->when($hasExplicitCartId, fn ($query) => $query->whereNull('cart_id'))
            ->orderBy('created_at')
            ->get();

        if ($attempts->isEmpty()) {
            return $insights;
        }

        $candidateUniverse = $this->candidateCartUniverse(
            $legacyEligibleCarts,
            $userIds,
            now()->setTimestamp($minCreatedAt)->subMinutes(self::WINDOW_START_TOLERANCE_MINUTES),
            now()->setTimestamp($maxUpdatedAt)->addHours(self::WINDOW_END_TOLERANCE_HOURS),
        );

        $candidateCartIdsByAttemptId = [];
        foreach ($attempts as $attempt) {
            $candidateCartIdsByAttemptId[$attempt->id] = $candidateUniverse
                ->filter(fn (Cart $cart) => $this->attemptBelongsToCart($attempt, $cart))
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        foreach ($legacyEligibleCarts as $cart) {
            $candidates = $attempts
                ->filter(fn (PaymentAttempt $attempt) => in_array((int) $cart->id, $candidateCartIdsByAttemptId[$attempt->id] ?? [], true))
                ->values();

            if ($candidates->isEmpty()) {
                continue;
            }

            $hasCompetingCart = $candidates->contains(
                fn (PaymentAttempt $attempt) => count($candidateCartIdsByAttemptId[$attempt->id] ?? []) > 1,
            );

            if ($hasCompetingCart) {
                $insights[(int) $cart->id] = [
                    'confidence' => 'ambiguous',
                    'status' => 'ambiguous',
                    'status_label' => 'Pago no determinado',
                    'attempts_count' => $candidates->count(),
                    'should_display' => false,
                ];

                continue;
            }

            $latest = $candidates
                ->sortByDesc(fn (PaymentAttempt $attempt) => $this->attemptTimestamp($attempt)?->timestamp ?? 0)
                ->first();

            $insights[(int) $cart->id] = $this->serializeInsight($latest, $candidates, 'legacy_high');
        }

        return $insights;
    }

    /**
     * @param  Collection<int, Cart>  $eligibleCarts
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, Cart>
     */
    private function candidateCartUniverse(
        Collection $eligibleCarts,
        Collection $userIds,
        CarbonInterface $windowStart,
        CarbonInterface $windowEnd,
    ): Collection {
        if ($userIds->isEmpty()) {
            return $eligibleCarts;
        }

        $extraCarts = Cart::query()
            ->with('user.customer')
            ->whereIn('user_id', $userIds)
            ->whereNotIn('id', $eligibleCarts->pluck('id')->filter()->values())
            ->where('created_at', '<=', $windowEnd)
            ->where(function ($query) use ($windowStart) {
                $query->where('updated_at', '>=', $windowStart)
                    ->orWhere('completed_at', '>=', $windowStart);
            })
            ->get();

        return $eligibleCarts
            ->concat($extraCarts)
            ->unique(fn (Cart $cart) => (int) $cart->id)
            ->values();
    }

    private function attemptBelongsToCart(PaymentAttempt $attempt, Cart $cart): bool
    {
        $customerId = $cart->user?->customer?->id;
        if (! $customerId || (int) $attempt->customer_id !== (int) $customerId) {
            return false;
        }

        if (! $this->amountMatchesCart($attempt, $cart)) {
            return false;
        }

        $attemptCreatedAt = $attempt->created_at;
        if (! $attemptCreatedAt || ! $cart->created_at || ! $cart->updated_at) {
            return false;
        }

        $startsAt = $cart->created_at->copy()->subMinutes(self::WINDOW_START_TOLERANCE_MINUTES);
        $endsAt = ($cart->completed_at ?? $cart->updated_at)->copy()->addHours(self::WINDOW_END_TOLERANCE_HOURS);

        return $attemptCreatedAt->betweenIncluded($startsAt, $endsAt);
    }

    private function amountMatchesCart(PaymentAttempt $attempt, Cart $cart): bool
    {
        $cartAmountCents = (int) round((float) $cart->total * 100);

        return abs((int) $attempt->amount_cents - $cartAmountCents) <= self::AMOUNT_TOLERANCE_CENTS;
    }

    /**
     * @param  Collection<int, PaymentAttempt>  $attempts
     * @return array<string, mixed>
     */
    private function serializeInsight(PaymentAttempt $latest, Collection $attempts, string $confidence): array
    {
        $occurredAt = $this->attemptTimestamp($latest);
        $status = (string) $latest->status;

        return [
            'confidence' => $confidence,
            'gateway' => self::GATEWAY,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_tone' => $this->statusTone($status),
            'attempts_count' => $attempts->count(),
            'last_attempt' => [
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'processor_code' => $this->safeProcessorCode($latest->processor_code),
                'processor_message' => $this->safeProcessorMessage($latest->processor_message, $status),
                'occurred_at' => $occurredAt?->toIso8601String(),
                'occurred_at_human' => $occurredAt?->timezone('America/Monterrey')->format('d/m/Y H:i'),
                'occurred_for_label' => $occurredAt ? $this->elapsedLabel($occurredAt) : null,
            ],
            'should_display' => true,
        ];
    }

    private function attemptTimestamp(PaymentAttempt $attempt): ?CarbonInterface
    {
        return $attempt->processed_at ?? $attempt->updated_at ?? $attempt->created_at;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'Intento pendiente',
            PaymentAttempt::STATUS_APPROVED => 'Pago aprobado',
            PaymentAttempt::STATUS_DECLINED => 'Pago rechazado',
            PaymentAttempt::STATUS_ERROR => 'Error técnico',
            PaymentAttempt::STATUS_REFUNDED => 'Reembolsado',
            default => 'Pago no determinado',
        };
    }

    private function statusTone(string $status): string
    {
        return match ($status) {
            PaymentAttempt::STATUS_APPROVED => 'green',
            PaymentAttempt::STATUS_DECLINED, PaymentAttempt::STATUS_ERROR => 'red',
            PaymentAttempt::STATUS_PENDING, PaymentAttempt::STATUS_PROCESSING => 'amber',
            PaymentAttempt::STATUS_REFUNDED => 'violet',
            default => 'zinc',
        };
    }

    private function safeProcessorCode(?string $code): ?string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return null;
        }

        $safe = preg_replace('/[^A-Za-z0-9._:-]/', '', $code);

        return $safe !== '' ? mb_substr($safe, 0, 24) : null;
    }

    private function safeProcessorMessage(?string $message, string $status): ?string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return $status === PaymentAttempt::STATUS_ERROR ? 'Error del procesador' : null;
        }

        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? '';
        $message = preg_replace('/\s+/', ' ', $message) ?? '';
        $lower = mb_strtolower($message);

        if (str_contains($lower, 'timeout') || str_contains($lower, 'time out')) {
            return 'Tiempo de espera agotado';
        }

        if (str_contains($lower, 'declin') || str_contains($lower, 'rechaz')) {
            return 'Transacción rechazada';
        }

        if (
            str_contains($lower, 'token')
            || str_contains($lower, 'card')
            || str_contains($lower, 'tarjeta')
            || str_contains($lower, '{')
            || str_contains($lower, '[')
        ) {
            return $status === PaymentAttempt::STATUS_ERROR ? 'Error del procesador' : null;
        }

        return mb_strlen($message) > 80
            ? mb_substr($message, 0, 77).'...'
            : $message;
    }

    private function elapsedLabel(CarbonInterface $at): string
    {
        $minutes = max(0, $at->diffInMinutes(now()));
        if ($minutes < 60) {
            return 'hace '.$minutes.' min';
        }

        $hours = intdiv($minutes, 60);
        if ($hours < 24) {
            return 'hace '.$hours.' h';
        }

        return 'hace '.intdiv($hours, 24).' d';
    }
}
