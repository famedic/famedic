<?php

namespace App\Mail;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponCreatedAuthorizerMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public Coupon $coupon,
        public User $creator,
        public array $summary,
        public string $detailUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuevo cupón creado — pendiente de revisión',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-created-authorizer',
        );
    }
}
