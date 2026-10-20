<?php

namespace App\Http\Controllers;

use App\Actions\Users\GenerateInvitationUrlAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class UserInvitationController extends Controller
{
    public function __invoke(Request $request, GenerateInvitationUrlAction $generateInvitationUrlAction)
    {
        $user = $request->user();

        $referrals = $user->referrals()
            ->latest()
            ->get()
            ->map(function ($referral) {
                $isRegistered = (bool) ($referral->email_verified_at || $referral->profile_is_complete);

                return [
                    'id' => $referral->id,
                    'initials' => $this->initialsFor($referral->full_name ?: $referral->email),
                    'name' => $referral->full_name ?: 'Invitado Famedic',
                    'email' => $referral->email,
                    'invited_at' => localizedDate($referral->created_at)?->isoFormat('D MMM YYYY'),
                    'status' => $isRegistered ? 'registered' : 'pending',
                    'status_label' => $isRegistered ? 'Registrado' : 'Pendiente',
                    'benefit_label' => $isRegistered ? 'Beneficio otorgado' : 'Sin beneficio aun',
                    'benefit_granted' => $isRegistered,
                ];
            })
            ->values();

        $registeredCount = $referrals->where('status', 'registered')->count();

        return Inertia::render('User/Invitations', [
            'invitationUrl' => $generateInvitationUrlAction($user),
            'invitationStats' => [
                'invited' => $referrals->count(),
                'registered' => $registeredCount,
                'benefits' => $registeredCount,
            ],
            'invitations' => $referrals,
        ]);
    }

    private function initialsFor(string $value): string
    {
        $cleanValue = trim(str_replace(['.', '_', '-'], ' ', $value));
        $parts = preg_split('/\s+/', $cleanValue, flags: PREG_SPLIT_NO_EMPTY) ?: [];

        if ($parts === [] && str_contains($value, '@')) {
            $parts = [strstr($value, '@', true) ?: $value];
        }

        return collect($parts)
            ->take(2)
            ->map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->join('') ?: 'FM';
    }
}
