<?php

namespace App\Enums;

enum LaboratoryAppointmentInteractionType: string
{
    case PatientPhoneIntent = 'patient_phone_intent';
    case PatientWhatsAppIntent = 'patient_whatsapp_intent';
    case PatientCallbackPreference = 'patient_callback_preference';
    case ConciergeNote = 'concierge_note';
    case ConciergeOutboundCall = 'concierge_outbound_call';
    case AdminBulkSoftDelete = 'admin_bulk_soft_delete';
}
