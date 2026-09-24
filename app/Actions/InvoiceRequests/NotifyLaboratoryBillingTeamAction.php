<?php

namespace App\Actions\InvoiceRequests;

use App\Enums\InvoiceRequestStatusLogTrigger;
use App\Models\Administrator;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\Permission;
use App\Models\User;
use App\Notifications\LaboratoryPurchaseInvoiceRequested;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;

class NotifyLaboratoryBillingTeamAction
{
    /**
     * @return Collection<int, User>
     */
    public function recipients(): Collection
    {
        $environment = App::environment();
        $isTestEnvironment = in_array($environment, ['local', 'staging', 'testing'], true);

        if ((bool) config('services.laboratory_invoice_request.skip_admin_mail')) {
            return collect();
        }

        if ($isTestEnvironment) {
            $testEmail = (string) config('services.laboratory_invoice_request.test_notify_email');
            $testUser = $testEmail !== '' ? User::where('email', $testEmail)->first() : null;

            if ($testUser) {
                return collect([$testUser]);
            }

            if (config('services.laboratory_invoice_request.allow_fallback_to_invoice_admins')) {
                return $this->invoiceNotificationRecipients();
            }

            return collect();
        }

        return $this->invoiceNotificationRecipients();
    }

    public function execute(LaboratoryPurchase $purchase, InvoiceRequest $invoiceRequest): bool
    {
        $users = $this->recipients();

        if ($users->isEmpty()) {
            Log::info('Solicitud de factura laboratorio: sin destinatarios para notificación.', [
                'laboratory_purchase_id' => $purchase->id,
                'invoice_request_id' => $invoiceRequest->id,
            ]);

            return false;
        }

        foreach ($users as $user) {
            $user->notify(new LaboratoryPurchaseInvoiceRequested($purchase));
        }

        Log::info('Notificaciones de solicitud de factura (laboratorio) enviadas.', [
            'laboratory_purchase_id' => $purchase->id,
            'invoice_request_id' => $invoiceRequest->id,
            'total_users' => $users->count(),
        ]);

        return true;
    }

    /**
     * @return Collection<int, User>
     */
    private function invoiceNotificationRecipients(): Collection
    {
        $users = collect();
        $roles = Permission::whereName('laboratory-purchases.manage.invoices')->sole()->roles;

        foreach ($roles as $role) {
            $administrators = Administrator::role($role->name)->get();
            $users = $users->merge($administrators->pluck('user'));
        }

        return $users->unique('id')->values();
    }
}
