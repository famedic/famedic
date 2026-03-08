<?php
// app/Actions/Laboratory/FindContactByPatientIdAction.php

namespace App\Actions\Laboratory;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class FindContactByPatientIdAction
{
    public function execute(string $patientId): ?Contact
    {
        $columns = Schema::getColumnListing('contacts');
        
        // Búsqueda por campos específicos
        $searchFields = [
            'external_patient_id',
            'gda_patient_id',
            'patient_id',
            'external_id'
        ];

        foreach ($searchFields as $field) {
            if (in_array($field, $columns)) {
                $contact = Contact::where($field, $patientId)->first();
                if ($contact) {
                    Log::info('Found contact by ' . $field, [
                        'patient_id' => $patientId,
                        'contact_id' => $contact->id
                    ]);
                    return $contact;
                }
            }
        }

        // Búsqueda en campos de texto
        $contact = Contact::where(function ($query) use ($patientId, $columns) {
            $textFields = ['notes', 'metadata', 'additional_info'];
            foreach ($textFields as $field) {
                if (in_array($field, $columns)) {
                    $query->orWhere($field, 'LIKE', '%' . $patientId . '%');
                }
            }
        })->first();

        if ($contact) {
            Log::info('Found contact by text search', [
                'patient_id' => $patientId,
                'contact_id' => $contact->id
            ]);
            return $contact;
        }

        return null;
    }
}