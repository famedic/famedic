<?php
// app/Actions/Laboratory/FindReferencesAction.php

namespace App\Actions\Laboratory;

use App\Models\LaboratoryQuote;
use App\Models\LaboratoryPurchase;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindReferencesAction
{
    protected FindQuoteAction $findQuoteAction;
    protected FindPurchaseAction $findPurchaseAction;
    protected FindContactByPatientIdAction $findContactAction;

    public function __construct(
        FindQuoteAction $findQuoteAction,
        FindPurchaseAction $findPurchaseAction,
        FindContactByPatientIdAction $findContactAction
    ) {
        $this->findQuoteAction = $findQuoteAction;
        $this->findPurchaseAction = $findPurchaseAction;
        $this->findContactAction = $findContactAction;
    }

    public function execute(array $data): array
    {
        $references = [
            'quote_id' => null,
            'purchase_id' => null,
            'user_id' => null,
            'contact_id' => null
        ];
        
        $gdaOrderId = $data['id'];
        $gdaExternalId = $data['requisition']['value'] ?? null;
        $gdaAcuse = $data['GDA_menssage']['acuse'] ?? null;

        Log::info('Searching references', [
            'gda_order_id' => $gdaOrderId,
            'gda_external_id' => $gdaExternalId,
            'gda_acuse' => $gdaAcuse
        ]);

        // Estrategia 1: Buscar quote
        $quote = $this->findQuoteAction->execute($gdaOrderId, $gdaExternalId, $gdaAcuse);
        
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

        // Estrategia 2: Buscar purchase directamente
        if (empty($references['purchase_id'])) {
            $purchase = $this->findPurchaseAction->execute($gdaOrderId, $gdaExternalId, $gdaAcuse);
            
            if ($purchase) {
                $references['purchase_id'] = $purchase->id;
                
                // Cargar la relación customer
                $purchase->load('customer');
                
                if ($purchase->customer) {
                    $references['user_id'] = $purchase->customer->user_id;
                    $references['contact_id'] = $this->validateContactId($purchase->customer->id);
                }

                $relatedQuote = LaboratoryQuote::where('laboratory_purchase_id', $purchase->id)->first();
                if ($relatedQuote && empty($references['quote_id'])) {
                    $references['quote_id'] = $relatedQuote->id;
                    
                    // Si no tenemos contact_id del purchase, intentar del quote
                    if (empty($references['contact_id'])) {
                        $references['contact_id'] = $this->validateContactId($relatedQuote->contact_id);
                    }
                }
            }
        }

        // Estrategia 3: Buscar por patient ID
        if (!empty($data['subject']['reference']) && empty($references['contact_id'])) {
            $patientId = $this->extractPatientId($data['subject']['reference']);
            if ($patientId) {
                $contact = $this->findContactAction->execute($patientId);
                if ($contact) {
                    $references['contact_id'] = $this->validateContactId($contact->id);
                    $references['user_id'] = $contact->user_id;
                }
            }
        }

        // Limpiar contact_id si no es válido
        if (!empty($references['contact_id']) && !$this->validateContactId($references['contact_id'])) {
            Log::warning('Contact ID invalid, setting to null', [
                'contact_id' => $references['contact_id']
            ]);
            $references['contact_id'] = null;
        }

        Log::info('Final references found', $references);
        return $references;
    }

    protected function extractPatientId(string $reference): ?string
    {
        if (preg_match('/Patient\/(\d+)/', $reference, $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Validar que el contact_id exista en la base de datos
     */
    protected function validateContactId($contactId): ?int
    {
        if (empty($contactId)) {
            return null;
        }

        // Verificar que sea un ID válido
        if (!is_numeric($contactId)) {
            return null;
        }

        // Verificar que exista en la tabla contacts
        $exists = Contact::where('id', $contactId)->exists();
        
        if (!$exists) {
            Log::warning('Contact ID does not exist in database', [
                'contact_id' => $contactId
            ]);
            return null;
        }

        return (int) $contactId;
    }
}