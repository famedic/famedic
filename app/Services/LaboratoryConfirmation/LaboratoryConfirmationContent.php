<?php

namespace App\Services\LaboratoryConfirmation;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Models\LaboratoryPurchase;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;

class LaboratoryConfirmationContent
{
    public function __construct(private readonly LaboratoryPreparationPresenter $presenter) {}

    public function presentation(LaboratoryPurchase $purchase): array
    {
        return $this->presenter->present($purchase);
    }

    public function snapshot(LaboratoryPurchase $purchase, array $presentation, bool $auto): array
    {
        if (! $auto) {
            // The presenter's purchase-item originals remain the only fallback source.
            $presentation['has_ai_summary'] = false;
            $presentation['ai_status'] = LaboratoryPreparationPresenter::STATUS_FALLBACK;
            $presentation['source'] = 'fallback';
            $presentation['summary'] = ['text' => null, 'sections' => [], 'special_instructions' => [], 'individual_instructions' => []];
        }
        $user = $purchase->customer?->user;
        if (! $user || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('missing_recipient');
        }
        $data = LaboratoryPurchaseConfirmationViewData::build($purchase, $user, false, $presentation);
        $data['unifiedConfirmation'] = true;
        $withAppointment = LaboratoryPurchaseConfirmationViewData::hasAppointmentForConfirmation($purchase);
        $notification = new LaboratoryPurchaseCreated($purchase, $data, $withAppointment);
        $mail = $notification->toMail($user);

        return ['recipient' => $user->email, 'subject' => $mail->subject, 'html' => (string) $mail->render(),
            'text' => (string) app(\Illuminate\Mail\Markdown::class)->renderText($mail->markdown, $mail->data())];
    }
}
