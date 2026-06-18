<?php

namespace App\Mail;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponBalanceActivatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public Coupon $childCoupon,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tu saldo a favor ya está disponible',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-balance-activated',
            with: [
                'formattedAmount' => formattedCentsPrice((int) $this->childCoupon->amount_cents),
                'validFrom' => $this->childCoupon->valid_from,
                'expiresAt' => $this->childCoupon->expires_at,
                'formattedMinPurchase' => $this->childCoupon->formatted_min_purchase,
                'validityStatus' => $this->childCoupon->validity_status,
            ],
        );
    }
}
