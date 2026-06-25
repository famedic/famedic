<?php

namespace App\Mail;

use App\Models\Coupon;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponAuthorizationDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public Coupon $coupon,
        public User $actor,
        public array $payload,
    ) {}

    public function envelope(): Envelope
    {
        $event = (string) ($this->payload['event'] ?? 'update');

        $subject = match ($event) {
            'master_approved', 'assignment_approved_final' => 'Autorización aprobada — Famedic',
            'assignment_approved_partial' => 'Nueva aprobación registrada — Famedic',
            'master_rejected', 'assignment_rejected' => 'Autorización rechazada — Famedic',
            default => 'Actualización de autorización — Famedic',
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-authorization-decision',
            with: [
                'coupon' => $this->coupon,
                'actor' => $this->actor,
                'payload' => $this->payload,
            ],
        );
    }
}
