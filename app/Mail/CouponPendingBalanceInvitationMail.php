<?php

namespace App\Mail;

use App\Models\Coupon;
use App\Models\CouponBeneficiary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponPendingBalanceInvitationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public CouponBeneficiary $beneficiary,
        public Coupon $parentCoupon,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tienes saldo a favor pendiente en Famedic',
        );
    }

    public function content(): Content
    {
        $displayName = trim(implode(' ', array_filter([
            $this->beneficiary->first_name,
            $this->beneficiary->paternal_lastname,
            $this->beneficiary->maternal_lastname,
        ])));

        return new Content(
            markdown: 'emails.coupon-pending-balance-invitation',
            with: [
                'displayName' => $displayName !== '' ? $displayName : null,
                'recipientEmail' => $this->beneficiary->email,
                'formattedAmount' => formattedCentsPrice((int) $this->parentCoupon->amount_cents),
                'validFrom' => $this->parentCoupon->valid_from,
                'expiresAt' => $this->parentCoupon->expires_at,
                'formattedMinPurchase' => $this->parentCoupon->formatted_min_purchase,
                'validityStatus' => $this->parentCoupon->validity_status,
            ],
        );
    }
}
