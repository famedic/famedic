<?php

namespace App\Http\Controllers;

use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Http\Controllers\Controller;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryQuote;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Exception;

class LaboratoryQuoteController extends Controller
{
    public function __construct(
        protected CreateGDAQuotationAction $createGDAQuotationAction
    ) {
    }

    public function store(Request $request, string $laboratory_brand)
    {
        $request->validate([
            'address_id' => 'required|exists:addresses,id',
            'contact_id' => 'required|exists:contacts,id',
            'cart_items' => 'required|array|min:1',
            'cart_items.*.test_id' => 'required|integer|exists:laboratory_tests,id',
            'cart_items.*.name' => 'required|string',
            'cart_items.*.price' => 'required|numeric',
            'cart_items.*.quantity' => 'nullable|integer|min:1',
        ]);

        $cartItems = $request->input('cart_items');

        try {
            $gdaResponse = ($this->createGDAQuotationAction)($cartItems);

            $total = collect($cartItems)->sum(fn($i) => $i['price'] * ($i['quantity'] ?? 1));

            $quote = LaboratoryQuote::create([
                'user_id' => auth()->id(),
                'customer_id' => auth()->user()->customer->id,
                'laboratory_brand' => $laboratory_brand,
                'contact_id' => $request->contact_id,
                'address_id' => $request->address_id,
                'items' => $cartItems,
                'subtotal' => $total,
                'discount' => 0,
                'total' => $total,
                'status' => 'pending_branch_payment',
                'gda_response' => $gdaResponse,
                'gda_acuse' => $gdaResponse['GDA_menssage']['acuse'] ?? null,
                'pdf_base64' => $gdaResponse['base64'] ?? null,
                'expires_at' => now()->addHours(24),
            ]);

            return Inertia::location(route('laboratory.quote.success', $quote->id));

        } catch (Exception $e) {
            return back()->with('error', 'Error al generar cotización: ' . $e->getMessage());
        }
    }

    public function success(LaboratoryQuote $quote)
    {
        // Cargar las relaciones necesarias
        $quote->load(['contact', 'address', 'appointment.laboratoryStore']);

        // Preparar los datos para Inertia
        $quoteData = [
            'id' => $quote->id,
            'gda_acuse' => $quote->gda_acuse,
            'total_cents' => $quote->total_cents,
            'subtotal_cents' => (int)($quote->subtotal * 100),
            'discount_cents' => (int)($quote->discount * 100),
            'expires_at' => $quote->expires_at,
            'created_at' => $quote->created_at,
            'status' => $quote->status,
            'patient_name' => $quote->appointment?->patientFullName ?? 'Paciente',
            'items' => $quote->items,
            'pdf_base64' => $quote->pdf_base64,
        ];

        // Solo agregar contact si existe la relación y el contacto
        if ($quote->relationLoaded('contact') && $quote->contact) {
            $quoteData['contact'] = [
                'name' => $quote->contact->name,
                'phone' => $quote->contact->phone,
                'email' => $quote->contact->email,
            ];
        } else {
            $quoteData['contact'] = null;
        }

        // Solo agregar address si existe la relación y la dirección
        if ($quote->relationLoaded('address') && $quote->address) {
            $quoteData['address'] = [
                'street' => $quote->address->street,
                'street_number' => $quote->address->street_number,
                'interior_number' => $quote->address->interior_number,
                'neighborhood' => $quote->address->neighborhood,
                'city' => $quote->address->city,
                'state' => $quote->address->state,
                'zip_code' => $quote->address->zip_code,
                'full_address' => $quote->address->full_address,
            ];
        } else {
            $quoteData['address'] = null;
        }

        // Solo agregar appointment si existe la relación y la cita
        if ($quote->relationLoaded('appointment') && $quote->appointment) {
            $quoteData['appointment'] = [
                'scheduled_at' => $quote->appointment->scheduled_at,
                'laboratory_store' => $quote->appointment->laboratoryStore ? [
                    'name' => $quote->appointment->laboratoryStore->name,
                    'address' => $quote->appointment->laboratoryStore->address,
                ] : null,
            ];
        } else {
            $quoteData['appointment'] = null;
        }

        return inertia('LaboratoryQuoteSuccess', [
            'quote' => $quoteData,
            'laboratoryBrand' => [
                'name' => strtoupper($quote->laboratory_brand),
                'imageSrc' => 'logo-gda.png'
            ]
        ]);
    }
}