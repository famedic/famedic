<?php

namespace App\Mail;

use App\Models\Coupon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponAuthorizationRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Coupon $coupon,
        public string $plainCode
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Autorización de cupón saldo — Famedic',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-authorization-requested',
            with: [
                'coupon' => $this->coupon,
                'plainCode' => $this->plainCode,
                'formattedAmount' => formattedCentsPrice($this->coupon->amount_cents),
            ],
        );
    }
}
