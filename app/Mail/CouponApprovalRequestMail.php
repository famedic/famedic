<?php

namespace App\Mail;

use App\Models\CouponApprovalRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CouponApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public CouponApprovalRequest $approvalRequest,
        public string $approvalUrl
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Solicitud de aprobación de cupones — Famedic',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.coupon-approval-request',
            with: [
                'approvalRequest' => $this->approvalRequest,
                'approvalUrl' => $this->approvalUrl,
            ],
        );
    }
}
