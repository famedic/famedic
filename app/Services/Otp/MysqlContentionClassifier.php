<?php

namespace App\Services\Otp;

use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Classifies MySQL/SQLite driver contention and uniqueness failures by SQLSTATE
 * and driver error code — never by free-text message matching alone.
 */
final class MysqlContentionClassifier
{
    public const KIND_DEADLOCK = 'deadlock';

    public const KIND_LOCK_WAIT_TIMEOUT = 'lock_wait_timeout';

    public const KIND_DUPLICATE_KEY = 'duplicate_key';

    public const KIND_OTHER = 'other';

    /**
     * @return array{kind: string, sqlstate: string|null, driver_code: int|null, duplicate_users_phone: bool}
     */
    public function classify(\Throwable $e): array
    {
        $sqlstate = null;
        $driverCode = null;
        $message = $e->getMessage();

        if ($e instanceof QueryException) {
            $sqlstate = $e->errorInfo[0] ?? null;
            $driverCode = isset($e->errorInfo[1]) ? (int) $e->errorInfo[1] : null;
            if (isset($e->errorInfo[2]) && is_string($e->errorInfo[2])) {
                $message .= ' '.$e->errorInfo[2];
            }
        }

        if ($e instanceof UniqueConstraintViolationException) {
            return [
                'kind' => self::KIND_DUPLICATE_KEY,
                'sqlstate' => $sqlstate ?? '23000',
                'driver_code' => $driverCode ?? 1062,
                'duplicate_users_phone' => $this->mentionsUsersPhoneUnique($message),
            ];
        }

        // MySQL deadlock: 1213 / SQLSTATE 40001
        if ($driverCode === 1213 || $sqlstate === '40001') {
            return [
                'kind' => self::KIND_DEADLOCK,
                'sqlstate' => $sqlstate,
                'driver_code' => $driverCode,
                'duplicate_users_phone' => false,
            ];
        }

        // MySQL lock wait timeout: 1205 / SQLSTATE HY000 (commonly)
        if ($driverCode === 1205) {
            return [
                'kind' => self::KIND_LOCK_WAIT_TIMEOUT,
                'sqlstate' => $sqlstate,
                'driver_code' => $driverCode,
                'duplicate_users_phone' => false,
            ];
        }

        // Duplicate entry: 1062 / SQLSTATE 23000
        if ($driverCode === 1062 || $sqlstate === '23000') {
            return [
                'kind' => self::KIND_DUPLICATE_KEY,
                'sqlstate' => $sqlstate,
                'driver_code' => $driverCode,
                'duplicate_users_phone' => $this->mentionsUsersPhoneUnique($message),
            ];
        }

        return [
            'kind' => self::KIND_OTHER,
            'sqlstate' => is_string($sqlstate) ? $sqlstate : null,
            'driver_code' => $driverCode,
            'duplicate_users_phone' => false,
        ];
    }

    public function isRetryableDeadlock(\Throwable $e): bool
    {
        return $this->classify($e)['kind'] === self::KIND_DEADLOCK;
    }

    private function mentionsUsersPhoneUnique(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'users_phone_country_phone_unique')
            || (str_contains($lower, 'phone_country') && str_contains($lower, 'phone'));
    }
}
