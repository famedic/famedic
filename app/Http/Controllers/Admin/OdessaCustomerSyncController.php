<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Odessa\SyncOdessaUserDataAction;
use App\Exceptions\OdessaGetUserDataFailedException;
use App\Exceptions\OdessaUserDataSyncMismatchException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Customers\OdessaUserDataSyncRequest;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Support\Odessa\OdessaApiErrorFormatter;
use App\Support\Odessa\OdessaUserDataSyncPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class OdessaCustomerSyncController extends Controller
{
    public const SESSION_KEY = 'odessa_user_data_sync_preview';

    public function preview(
        OdessaUserDataSyncRequest $request,
        Customer $customer,
        SyncOdessaUserDataAction $syncOdessaUserDataAction,
    ): RedirectResponse {
        $account = $request->resolveOdessaAfiliateAccount();

        try {
            $result = $syncOdessaUserDataAction($account, dryRun: true);

            $request->session()->put(self::SESSION_KEY, array_merge(
                [
                    'customer_id' => $customer->id,
                    'error' => null,
                    'error_type' => null,
                ],
                OdessaUserDataSyncPresenter::fromResult($result),
            ));
        } catch (OdessaUserDataSyncMismatchException $e) {
            $request->session()->put(self::SESSION_KEY, [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
                'error_type' => 'mismatch',
            ]);
        } catch (OdessaGetUserDataFailedException $e) {
            $request->session()->put(self::SESSION_KEY, [
                'customer_id' => $customer->id,
                'error' => OdessaApiErrorFormatter::summarize($e->getMessage()),
                'error_type' => 'api_error',
            ]);
        } catch (Throwable $e) {
            $request->session()->put(self::SESSION_KEY, [
                'customer_id' => $customer->id,
                'error' => OdessaApiErrorFormatter::summarize($e->getMessage()),
                'error_type' => 'error',
            ]);
        }

        return redirect()->route('admin.customers.show', $customer);
    }

    public function apply(
        OdessaUserDataSyncRequest $request,
        Customer $customer,
        SyncOdessaUserDataAction $syncOdessaUserDataAction,
    ): RedirectResponse {
        $preview = $request->session()->get(self::SESSION_KEY);

        if (! is_array($preview) || ($preview['customer_id'] ?? null) !== $customer->id) {
            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('error', 'No hay una previsualización activa. Consulta los datos Odessa primero.');
        }

        if (! empty($preview['error'])) {
            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('error', 'La previsualización tiene errores. Corrige el vínculo o vuelve a consultar Odessa.');
        }

        $account = $request->resolveOdessaAfiliateAccount();

        try {
            $result = $syncOdessaUserDataAction($account, dryRun: false);
            $request->session()->forget(self::SESSION_KEY);

            $message = $result->hasChanges()
                ? 'Metadata Odessa actualizada correctamente.'
                : 'La cuenta ya tenía los mismos valores; no hubo cambios.';

            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('success', $message);
        } catch (OdessaUserDataSyncMismatchException $e) {
            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('error', 'Validación de vínculo fallida: '.$e->getMessage());
        } catch (OdessaGetUserDataFailedException $e) {
            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('error', 'Odessa respondió con error: '.OdessaApiErrorFormatter::summarize($e->getMessage()));
        } catch (Throwable $e) {
            return redirect()
                ->route('admin.customers.show', $customer)
                ->with('error', 'Error al sincronizar metadata Odessa: '.OdessaApiErrorFormatter::summarize($e->getMessage()));
        }
    }

    public function clear(OdessaUserDataSyncRequest $request, Customer $customer): RedirectResponse
    {
        $preview = $request->session()->get(self::SESSION_KEY);

        if (is_array($preview) && ($preview['customer_id'] ?? null) === $customer->id) {
            $request->session()->forget(self::SESSION_KEY);
        }

        return redirect()
            ->route('admin.customers.show', $customer)
            ->with('success', 'Previsualización Odessa descartada.');
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function previewForCustomer(Request $request, Customer $customer): ?array
    {
        if ($customer->customerable_type !== OdessaAfiliateAccount::class) {
            return null;
        }

        $preview = $request->session()->get(self::SESSION_KEY);

        if (! is_array($preview) || ($preview['customer_id'] ?? null) !== $customer->id) {
            return null;
        }

        return $preview;
    }
}
