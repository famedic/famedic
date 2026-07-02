<?php
// app/Actions/Laboratory/FindReferencesAction.php

namespace App\Actions\Laboratory;

use App\Models\Contact;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Support\GDA\GdaWebhookPayloadResolver;
use Illuminate\Support\Facades\Log;

class FindReferencesAction
{
    public function __construct(
        protected FindQuoteAction $findQuoteAction,
        protected FindPurchaseAction $findPurchaseAction,
        protected FindContactByPatientIdAction $findContactAction,
        protected GdaWebhookPayloadResolver $payloadResolver,
    ) {
    }

    public function execute(array $data): array
    {
        $resolved = $this->payloadResolver->resolve($data);

        $references = [
            'quote_id' => null,
            'purchase_id' => null,
            'user_id' => null,
            'contact_id' => null,
            'gda' => $resolved,
        ];

        Log::info('Searching references', [
            'gda_order_id' => $resolved['gda_order_id'],
            'gda_consecutivo' => $resolved['gda_consecutivo'],
            'gda_external_id' => $resolved['gda_external_id'],
            'gda_acuse' => $resolved['acuse'],
            'is_gabinete' => $resolved['is_gabinete'],
        ]);

        $quote = $this->findQuoteAction->execute($resolved);

        if ($quote) {
            $references['quote_id'] = $quote->id;

            if ($quote->laboratory_purchase_id) {
                $purchase = LaboratoryPurchase::with('customer')->find($quote->laboratory_purchase_id);
                if ($purchase) {
                    $references['purchase_id'] = $purchase->id;
                    $references['user_id'] = $purchase->customer?->user_id ?? $quote->user_id;
                    $references['contact_id'] = $this->validateContactId($purchase->customer?->id ?? $quote->contact_id);
                }
            } else {
                $references['user_id'] = $quote->user_id;
                $references['contact_id'] = $this->validateContactId($quote->contact_id);
            }
        }

        if (empty($references['purchase_id'])) {
            $purchase = $this->findPurchaseAction->execute($resolved);

            if ($purchase) {
                $references['purchase_id'] = $purchase->id;

                $purchase->load('customer');

                if ($purchase->customer) {
                    $references['user_id'] = $purchase->customer->user_id;
                    $references['contact_id'] = $this->validateContactId($purchase->customer->id);
                }

                $relatedQuote = LaboratoryQuote::where('laboratory_purchase_id', $purchase->id)->first();
                if ($relatedQuote && empty($references['quote_id'])) {
                    $references['quote_id'] = $relatedQuote->id;

                    if (empty($references['contact_id'])) {
                        $references['contact_id'] = $this->validateContactId($relatedQuote->contact_id);
                    }
                }
            }
        }

        if (! empty($data['subject']['reference']) && empty($references['contact_id'])) {
            $patientId = $this->extractPatientId($data['subject']['reference']);
            if ($patientId) {
                $contact = $this->findContactAction->execute($patientId);
                if ($contact) {
                    $references['contact_id'] = $this->validateContactId($contact->id);
                    if (empty($references['user_id'])) {
                        $references['user_id'] = $contact->user_id;
                    }
                }
            }
        }

        if (! empty($references['contact_id']) && ! $this->validateContactId($references['contact_id'])) {
            Log::warning('Contact ID invalid, setting to null', [
                'contact_id' => $references['contact_id'],
            ]);
            $references['contact_id'] = null;
        }

        Log::info('Final references found', [
            'quote_id' => $references['quote_id'],
            'purchase_id' => $references['purchase_id'],
            'user_id' => $references['user_id'],
            'contact_id' => $references['contact_id'],
        ]);

        return $references;
    }

    protected function extractPatientId(string $reference): ?string
    {
        if (preg_match('/Patient\/(\d+)/', $reference, $matches)) {
            return $matches[1];
        }

        return null;
    }

    protected function validateContactId($contactId): ?int
    {
        if (empty($contactId)) {
            return null;
        }

        if (! is_numeric($contactId)) {
            return null;
        }

        $exists = Contact::where('id', $contactId)->exists();

        if (! $exists) {
            Log::warning('Contact ID does not exist in database', [
                'contact_id' => $contactId,
            ]);

            return null;
        }

        return (int) $contactId;
    }
}
