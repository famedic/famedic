<?php

namespace App\Enums;

enum CartEventType: string
{
    case CartCreated = 'cart_created';
    case CartItemAdded = 'cart_item_added';
    case CartItemRemoved = 'cart_item_removed';
    case CartItemQuantityChanged = 'cart_item_quantity_changed';
    case CartEmptied = 'cart_emptied';
    case CartAbandoned = 'cart_abandoned';
    case CartResumed = 'cart_resumed';
    case CartRecovered = 'cart_recovered';
    case CheckoutStarted = 'checkout_started';
    case PatientSelected = 'patient_selected';
    case AddressSelected = 'address_selected';
    case AppointmentRequested = 'appointment_requested';
    case AppointmentConfirmed = 'appointment_confirmed';
    case PaymentStarted = 'payment_started';
    case PaymentDeclined = 'payment_declined';
    case PaymentError = 'payment_error';
    case PaymentApproved = 'payment_approved';
    case PurchaseCreated = 'purchase_created';
    case CartCompleted = 'cart_completed';
}
