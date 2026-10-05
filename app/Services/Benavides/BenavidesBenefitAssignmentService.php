<?php

namespace App\Services\Benavides;

use App\Exceptions\OutOfBenavidesCodesException;
use App\Models\BenavidesCode;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class BenavidesBenefitAssignmentService
{
    public function assignTo(User $user): BenavidesCode
    {
        try {
            return $this->assignInsideTransaction($user);
        } catch (QueryException $exception) {
            if (! $this->isExpectedUniqueConstraintViolation($exception)) {
                throw $exception;
            }

            $existing = BenavidesCode::query()
                ->where('user_id', $user->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $exception;
        }
    }

    private function assignInsideTransaction(User $user): BenavidesCode
    {
        return DB::transaction(function () use ($user) {
            $existing = BenavidesCode::query()
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $code = BenavidesCode::query()
                ->whereNull('user_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $code) {
                throw new OutOfBenavidesCodesException;
            }

            $code->forceFill([
                'user_id' => $user->id,
                'assigned_at' => now(),
            ])->save();

            return $code->refresh();
        }, 3);
    }

    private function isExpectedUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? $exception->getCode());
        $message = $exception->getMessage();

        if (! in_array($sqlState, ['23000', '23505'], true) && ! in_array($driverCode, ['19', '1062', '23505'], true)) {
            return false;
        }

        return str_contains($message, 'benavides_codes_user_id_unique')
            || str_contains($message, 'benavides_codes.user_id')
            || str_contains($message, 'UNIQUE constraint failed: benavides_codes.user_id');
    }
}
