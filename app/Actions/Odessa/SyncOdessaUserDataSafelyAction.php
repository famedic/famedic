<?php

namespace App\Actions\Odessa;

use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaApiErrorFormatter;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncOdessaUserDataSafelyAction
{
    public function __construct(
        private SyncOdessaUserDataAction $syncOdessaUserDataAction,
    ) {}

    public function __invoke(OdessaAfiliateAccount $odessaAfiliateAccount): void
    {
        try {
            ($this->syncOdessaUserDataAction)($odessaAfiliateAccount);
        } catch (OdessaUserDataSyncMismatchException $e) {
            $this->logFailure($odessaAfiliateAccount, 'mismatch', $e);
        } catch (OdessaGetUserDataFailedException $e) {
            $this->logFailure($odessaAfiliateAccount, 'api_error', $e);
        } catch (Throwable $e) {
            $this->logFailure($odessaAfiliateAccount, 'error', $e);
        }
    }

    private function logFailure(
        OdessaAfiliateAccount $odessaAfiliateAccount,
        string $errorType,
        Throwable $exception,
    ): void {
        Log::warning('ODESSA_USER_DATA_SYNC_FAILED', [
            'odessa_afiliate_account_id' => $odessaAfiliateAccount->id,
            'odessa_identifier' => $odessaAfiliateAccount->odessa_identifier,
            'error_type' => $errorType,
            'reason' => OdessaApiErrorFormatter::summarize($exception->getMessage()),
        ]);
    }
}
