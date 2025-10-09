<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureNoDuplicateLaboratoryAppointment
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $customer = $request->user()->customer;

        $laboratoryBrand = $request->route('laboratory_brand');

        if (
            !$customer->getHasLaboratoryCartItemRequiringAppointment($laboratoryBrand) ||
            $customer->getRecentlyConfirmedUncompletedLaboratoryAppointment($laboratoryBrand)
        ) {
            return redirect()->route('laboratory.checkout', ['laboratory_brand' => $laboratoryBrand]);
        }

        $pendingLaboratoryAppointment = $customer->getPendingLaboratoryAppointment($laboratoryBrand);

        if ($pendingLaboratoryAppointment) {
            return redirect()->route('laboratory-appointments.show', [
                'laboratory_brand' => $laboratoryBrand,
                'laboratory_appointment' => $pendingLaboratoryAppointment
            ]);
        }

        return $next($request);
    }
}
