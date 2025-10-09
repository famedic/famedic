<?php

namespace App\Http\Controllers;

use App\Actions\MedicalAttention\CreateTrialSubscriptionAction;
use App\Exceptions\MurguiaConflictException;
use App\Http\Requests\MedicalAttention\FreeMedicalAttentionSubscriptionRequest;

class FreeMedicalAttentionSubscriptionController extends Controller
{
    public function __invoke(FreeMedicalAttentionSubscriptionRequest $request, CreateTrialSubscriptionAction $createTrialSubscriptionAction)
    {
        try {
            $createTrialSubscriptionAction($request->user()->customer);
            return redirect()->route('medical-attention')->with('confetti', true);
        } catch (MurguiaConflictException $e) {
            // For trial subscriptions, just show error - no payment to refund
            return redirect()->back()->flashMessage('No se pudo completar la suscripción. Por favor contacta soporte.', 'error');
        }
    }
}
