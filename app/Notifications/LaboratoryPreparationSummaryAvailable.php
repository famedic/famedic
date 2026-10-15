<?php

namespace App\Notifications;

use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchasePreparationSummary;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LaboratoryPreparationSummaryAvailable extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly LaboratoryPurchase $laboratoryPurchase,
        private readonly LaboratoryPurchasePreparationSummary $summary,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $purchase = $this->laboratoryPurchase;
        $summaryJson = is_array($this->summary->summary_json)
            ? $this->summary->summary_json
            : [];
        $orderUrl = route('laboratory-purchases.show', $purchase);
        $displayOrderId = filled($purchase->gda_order_id)
            ? (string) $purchase->gda_order_id
            : '#'.$purchase->id;
        $buyerName = trim((string) ($notifiable->full_name ?? ''));
        $patientName = trim((string) $purchase->full_name);
        $summaryText = trim((string) ($this->summary->summary_text ?: ($summaryJson['summary'] ?? '')));
        $sections = collect($summaryJson['sections'] ?? [])
            ->map(fn ($section) => [
                'title' => trim((string) ($section['title'] ?? 'Indicaciones')),
                'content' => $this->normalizePreparationText((string) ($section['content'] ?? '')),
            ])
            ->filter(fn ($section) => $section['content'] !== '')
            ->take(4)
            ->values()
            ->all();
        $specialInstructions = collect($summaryJson['special_instructions'] ?? [])
            ->map(fn ($instruction) => $this->normalizePreparationText((string) ($instruction['content'] ?? '')))
            ->filter()
            ->take(3)
            ->values()
            ->all();
        $individualInstructions = collect($summaryJson['individual_instructions'] ?? [])
            ->map(fn ($instruction) => [
                'study_name' => trim((string) ($instruction['study_name'] ?? 'Estudio')),
                'content' => $this->normalizePreparationText((string) ($instruction['content'] ?? '')),
            ])
            ->filter(fn ($instruction) => $instruction['content'] !== '')
            ->take(4)
            ->values()
            ->all();

        return (new MailMessage)
            ->subject('Tu resumen de indicaciones ya está disponible')
            ->markdown('emails.laboratory.preparation-summary-available', [
                'buyerName' => $buyerName !== '' ? $buyerName : null,
                'displayOrderId' => $displayOrderId,
                'patientName' => $patientName !== '' ? $patientName : null,
                'summaryText' => $summaryText,
                'sections' => $sections,
                'specialInstructions' => $specialInstructions,
                'individualInstructions' => $individualInstructions,
                'orderUrl' => $orderUrl,
            ]);
    }

    private function normalizePreparationText(string $text): string
    {
        $text = trim($text);

        if ($text === '' || ! str_contains($text, '•')) {
            return $text;
        }

        return trim((string) preg_replace('/\s*•\s*/u', "\n• ", $text));
    }

    public function toArray(object $notifiable): array
    {
        return [
            'laboratory_purchase_id' => $this->laboratoryPurchase->id,
            'laboratory_purchase_preparation_summary_id' => $this->summary->id,
        ];
    }
}
