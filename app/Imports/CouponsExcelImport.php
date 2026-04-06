<?php

namespace App\Imports;

use App\Models\User;
use App\Services\CouponService;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class CouponsExcelImport implements ToCollection, WithHeadingRow
{
    public function __construct(
        private CouponService $couponService,
        private bool $sendNotifications
    ) {}

    public function collection(\Illuminate\Support\Collection $rows): void
    {
        foreach ($rows as $row) {
            $email = $row->get('email') ?? $row->get('correo');
            $amount = $row->get('amount') ?? $row->get('monto');
            $code = $row->get('code') ?? $row->get('codigo');

            if (! $email || $amount === null || $amount === '') {
                continue;
            }

            $amountCents = (int) round((float) $amount * 100);
            if ($amountCents <= 0) {
                continue;
            }

            $user = User::where('email', trim((string) $email))->first();
            if (! $user) {
                continue;
            }

            $this->couponService->assignCouponToUser(
                $user,
                $amountCents,
                $this->sendNotifications,
                $code ? trim((string) $code) : null
            );
        }
    }
}
