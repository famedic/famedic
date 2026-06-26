<?php

namespace App\Support;

use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Models\User;

class MedicalMembershipPurchasedMailData
{
    public static function build(
        MedicalAttentionSubscription $subscription,
        User $user,
        ?Transaction $transaction,
        string $purchaseSource,
    ): array {
        $customer = $subscription->customer;
        $isPremium = (bool) $customer?->has_odessa_afiliate_account;
        $phoneNumber = $isPremium ? '5541697768' : '5594540058';
        $formattedPhone = $isPremium ? '55 4169 7768' : '55 9454 0058';

        $purchaseDate = $transaction?->created_at ?? $subscription->created_at;

        return [
            'nombre_usuario' => $user->full_name ?: $user->name,
            'famedic_logo_url' => config('famedic.email_public_url').'/images/logo.png',
            'plan_name' => 'Membresía Médica Anual',
            'plan_type_label' => $isPremium ? 'Membresía Premium' : 'Membresía Básica',
            'purchase_source_label' => self::purchaseSourceLabel($purchaseSource),
            'identifier' => $customer?->medical_attention_identifier,
            'formatted_phone' => $formattedPhone,
            'tel_href' => 'tel:+52'.$phoneNumber,
            'line_label' => $isPremium
                ? 'Línea de atención Premium'
                : 'Línea de atención Básica',
            'formatted_price' => $subscription->formatted_price,
            'formatted_start_date' => $subscription->formatted_start_date,
            'formatted_end_date' => $subscription->formatted_end_date,
            'formatted_purchase_date' => localizedDate($purchaseDate)
                ->isoFormat('D [de] MMM [de] YYYY, h:mm a'),
            'payment_method' => self::paymentMethodLabel(
                $transaction?->payment_method ?? $transaction?->gateway,
            ),
            'payment_status' => $transaction?->isSuccessfulPayment() ? 'Pagado' : 'Confirmado',
            'transaction_number' => $transaction
                ? self::formatTransactionNumber($transaction)
                : null,
            'benefits' => self::benefits(),
            'medical_attention_url' => route('medical-attention'),
            'membership_url' => route('membership.index'),
            'family_url' => route('family.index'),
        ];
    }

    /**
     * @return array<int, array{title: string, description: string}>
     */
    public static function benefits(): array
    {
        return [
            [
                'title' => 'Atención médica ilimitada',
                'description' => 'Consultas generales sin límite durante tu vigencia.',
            ],
            [
                'title' => 'Consultas 24/7',
                'description' => 'Médicos disponibles en cualquier momento del día.',
            ],
            [
                'title' => 'Telemedicina',
                'description' => 'Videoconsultas y chat con médicos generales.',
            ],
            [
                'title' => 'Psicología',
                'description' => 'Asistencia telefónica psicológica para ti y tu familia.',
            ],
            [
                'title' => 'Nutrición',
                'description' => 'Orientación nutricional profesional por teléfono.',
            ],
            [
                'title' => 'Atención para toda la familia',
                'description' => 'Cobertura para titular, cónyuge e hijos.',
            ],
        ];
    }

    public static function purchaseSourceLabel(string $purchaseSource): string
    {
        return match ($purchaseSource) {
            'laboratory_checkout' => 'Compra junto con pedido de laboratorio',
            default => 'Compra desde Atención médica',
        };
    }

    public static function paymentMethodLabel(?string $method): string
    {
        return match (strtolower((string) $method)) {
            'paypal' => 'PayPal',
            'efevoopay' => 'EfevooPay',
            'odessa' => 'Caja de ahorro',
            'stripe' => 'Tarjeta',
            'coupon_balance' => 'Crédito a favor',
            default => $method ? ucfirst(str_replace('_', ' ', $method)) : '—',
        };
    }

    public static function formatTransactionNumber(Transaction $transaction): string
    {
        return sprintf(
            'FM-%s-%06d',
            $transaction->created_at?->format('Y') ?? now()->format('Y'),
            $transaction->id,
        );
    }
}
