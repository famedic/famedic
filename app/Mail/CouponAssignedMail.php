<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponAssignedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $user,
        public int $amountCents
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Tienes saldo a favor en Famedic',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-assigned',
            with: [
                'formattedAmount' => formattedCentsPrice($this->amountCents),
            ],
        );
    }
}
