<?php

namespace App\Notifications;

use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use App\Support\MedicalMembershipPurchasedMailData;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MedicalMembershipPurchased extends Notification
{
    use Queueable;

    public function __construct(
        protected MedicalAttentionSubscription $subscription,
        protected ?Transaction $transaction,
        protected string $purchaseSource = 'medical_attention_checkout',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $data = MedicalMembershipPurchasedMailData::build(
            $this->subscription->loadMissing('customer'),
            $notifiable,
            $this->transaction,
            $this->purchaseSource,
        );

        return (new MailMessage)
            ->subject('Tu Membresía Médica Famedic está activa')
            ->markdown('emails.membership.purchased', $data);
    }

    public function toArray(object $notifiable): array
    {
        return [];
    }
}
