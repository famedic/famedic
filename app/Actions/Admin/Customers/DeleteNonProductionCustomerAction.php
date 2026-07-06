<?php

namespace App\Actions\Admin\Customers;

use App\Models\ActiveCampaignDispatch;
use App\Models\ArcoSolicitud;
use App\Models\Cart;
use App\Models\CouponBeneficiary;
use App\Models\CouponTransaction;
use App\Models\Customer;
use App\Models\DevAssistanceComment;
use App\Models\DevAssistanceRequest;
use App\Models\Efevoo3dsSession;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\LabResultAccessToken;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryQuote;
use App\Models\MedicalAttentionSubscription;
use App\Models\MurguiaSyncLog;
use App\Models\OnlinePharmacyPurchase;
use App\Models\OtpAccessLog;
use App\Models\OtpCode;
use App\Models\PaymentAttempt;
use App\Models\PromoRedemption;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VendorPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class DeleteNonProductionCustomerAction
{
    /** @var array<string, int> */
    private array $deletedCounts = [];

    /**
     * @return array<string, int>
     */
    public function execute(Customer $customer, User $actor): array
    {
        abort_if(app()->isProduction(), 403);

        $customer->loadMissing(['user.administrator', 'customerable']);

        if ($customer->user?->administrator) {
            throw new InvalidArgumentException('No se puede eliminar un usuario con cuenta de administrador.');
        }

        if ($customer->user_id === $actor->id) {
            throw new InvalidArgumentException('No puedes eliminar tu propia cuenta de usuario.');
        }

        $this->deletedCounts = $this->emptyCounts();

        $auditContext = [
            'actor_user_id' => $actor->id,
            'customer_id' => $customer->id,
            'user_id' => $customer->user_id,
            'environment' => app()->environment(),
        ];

        Log::info('DeleteNonProductionCustomerAction: inicio', $auditContext);

        DB::transaction(function () use ($customer) {
            $this->purgeCustomerTree($customer, deleteUser: true);
        });

        Log::info('DeleteNonProductionCustomerAction: completado', [
            ...$auditContext,
            'deleted_counts' => $this->deletedCounts,
        ]);

        return $this->deletedCounts;
    }

    private function purgeCustomerTree(Customer $customer, bool $deleteUser): void
    {
        $customer = Customer::withTrashed()->findOrFail($customer->id);

        if (Schema::hasTable('family_accounts')) {
            foreach ($customer->familyAccounts()->with('customer')->get() as $familyAccount) {
                $memberCustomer = $familyAccount->customer;

                if ($memberCustomer && $memberCustomer->id !== $customer->id) {
                    $this->purgeCustomerTree($memberCustomer, deleteUser: false);
                }
            }
        }

        $this->purgeCustomerRelatedData($customer);

        $customerable = $customer->customerable()->withTrashed()->first();
        $customer->forceDelete();
        $this->increment('customers');

        if ($customerable) {
            $this->forceDeleteModel($customerable, 'customerables');
        }

        if ($deleteUser && $customer->user_id) {
            $user = User::query()->find($customer->user_id);

            if ($user) {
                $this->purgeUserRelatedData($user);
                $user->delete();
                $this->increment('users');
            }
        }
    }

    private function purgeCustomerRelatedData(Customer $customer): void
    {
        if (Schema::hasTable('laboratory_purchases')) {
            $customer->laboratoryPurchases()
                ->withTrashed()
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteLaboratoryPurchase(LaboratoryPurchase::withTrashed()->find($id)));
        }

        if (Schema::hasTable('online_pharmacy_purchases')) {
            $customer->onlinePharmacyPurchases()
                ->withTrashed()
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteOnlinePharmacyPurchase(OnlinePharmacyPurchase::withTrashed()->find($id)));
        }

        if (Schema::hasTable('medical_attention_subscriptions')) {
            $customer->medicalAttentionSubscriptions()
                ->withTrashed()
                ->orderByDesc('parent_subscription_id')
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteMedicalAttentionSubscription(MedicalAttentionSubscription::withTrashed()->find($id)));
        }

        if (Schema::hasTable('laboratory_cart_items')) {
            $this->deleteModels($customer->laboratoryCartItems()->withTrashed()->get(), 'laboratory_cart_items');
        }

        if (Schema::hasTable('online_pharmacy_cart_items')) {
            $this->deleteModels($customer->onlinePharmacyCartItems()->withTrashed()->get(), 'online_pharmacy_cart_items');
        }

        if (Schema::hasTable('laboratory_checkout_drafts')) {
            $this->deleteModels($customer->laboratoryCheckoutDrafts()->get(), 'laboratory_checkout_drafts');
        }

        if (Schema::hasTable('laboratory_appointments')) {
            $customer->laboratoryAppointments()
                ->withTrashed()
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteLaboratoryAppointment(LaboratoryAppointment::withTrashed()->find($id)));
        }

        if (Schema::hasTable('laboratory_quotes')) {
            $customer->laboratoryQuotes()
                ->withTrashed()
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteLaboratoryQuote(LaboratoryQuote::withTrashed()->find($id)));
        }

        if (Schema::hasTable('efevoo_tokens')) {
            $customer->efevooTokens()
                ->withTrashed()
                ->pluck('id')
                ->each(fn (int $id) => $this->deleteEfevooToken(EfevooToken::withTrashed()->find($id)));
        }

        if (Schema::hasTable('efevoo_3ds_sessions')) {
            Efevoo3dsSession::query()
                ->where('customer_id', $customer->id)
                ->delete();
            $this->increment('efevoo_3ds_sessions');
        }

        if (Schema::hasTable('payment_attempts')) {
            PaymentAttempt::query()->where('customer_id', $customer->id)->delete();
            $this->increment('payment_attempts');
        }

        if (Schema::hasTable('murguia_sync_logs')) {
            MurguiaSyncLog::query()->where('customer_id', $customer->id)->delete();
            $this->increment('murguia_sync_logs');
        }

        if (Schema::hasTable('activecampaign_dispatches')) {
            ActiveCampaignDispatch::query()->where('customer_id', $customer->id)->delete();
            $this->increment('activecampaign_dispatches');
        }

        if (Schema::hasTable('promo_redemptions')) {
            PromoRedemption::query()->where('customer_id', $customer->id)->delete();
            $this->increment('promo_redemptions');
        }

        if (Schema::hasTable('contacts')) {
            $this->deleteModels($customer->contacts()->withTrashed()->get(), 'contacts');
        }

        if (Schema::hasTable('addresses')) {
            $this->deleteModels($customer->addresses()->withTrashed()->get(), 'addresses');
        }

        if (Schema::hasTable('tax_profiles')) {
            $this->deleteModels($customer->taxProfiles()->withTrashed()->get(), 'tax_profiles');
        }
    }

    private function purgeUserRelatedData(User $user): void
    {
        User::query()->where('referred_by', $user->id)->update(['referred_by' => null]);

        if (Schema::hasTable('notifications')) {
            $this->deleteModels($user->inAppNotifications()->get(), 'notifications');
        }

        if (Schema::hasTable('laboratory_notifications')) {
            $this->deleteModels($user->laboratoryNotifications()->withTrashed()->get(), 'laboratory_notifications');
        }

        if (Schema::hasTable('coupon_user')) {
            $this->deleteModels($user->couponUsers()->get(), 'coupon_user');
        }

        if (Schema::hasTable('coupon_transactions')) {
            $this->deleteModels(CouponTransaction::query()->where('user_id', $user->id)->get(), 'coupon_transactions');
        }

        if (Schema::hasTable('coupon_beneficiaries')) {
            $this->deleteModels(CouponBeneficiary::query()->where('user_id', $user->id)->get(), 'coupon_beneficiaries');
        }

        if (Schema::hasTable('otp_codes')) {
            $this->deleteModels(OtpCode::query()->where('user_id', $user->id)->get(), 'otp_codes');
        }

        if (Schema::hasTable('otp_access_logs')) {
            $this->deleteModels(OtpAccessLog::query()->where('user_id', $user->id)->get(), 'otp_access_logs');
        }

        if (Schema::hasTable('lab_result_access_tokens')) {
            $this->deleteModels(LabResultAccessToken::query()->where('user_id', $user->id)->get(), 'lab_result_access_tokens');
        }

        if (Schema::hasTable('arco_solicitudes')) {
            $this->deleteModels(ArcoSolicitud::query()->where('user_id', $user->id)->get(), 'arco_solicitudes');
        }

        if (Schema::hasTable('activecampaign_dispatches')) {
            $this->deleteModels(ActiveCampaignDispatch::query()->where('user_id', $user->id)->get(), 'activecampaign_dispatches');
        }

        if (Schema::hasTable('promo_redemptions')) {
            $this->deleteModels(PromoRedemption::query()->where('user_id', $user->id)->get(), 'promo_redemptions');
        }

        if (Schema::hasTable('carts')) {
            $user->monitoringCarts()->each(function (Cart $cart) {
                if (Schema::hasTable('cart_items')) {
                    $cart->items()->delete();
                }
                $cart->delete();
                $this->increment('carts');
            });
        }

        if (Schema::hasTable('laboratory_quotes')) {
            LaboratoryQuote::query()->where('user_id', $user->id)->pluck('id')->each(
                fn (int $id) => $this->deleteLaboratoryQuote(LaboratoryQuote::withTrashed()->find($id))
            );
        }

        if (Schema::hasTable('laboratory_notifications')) {
            LaboratoryNotification::query()->where('user_id', $user->id)->delete();
            $this->increment('laboratory_notifications');
        }

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $this->increment('sessions');
        }
    }

    private function deleteLaboratoryPurchase(?LaboratoryPurchase $purchase): void
    {
        if (! $purchase) {
            return;
        }

        $this->deleteDevAssistanceRequests($purchase);
        $this->deleteInvoiceRecords($purchase);
        $this->deleteTransactionables($purchase);
        $this->detachVendorPayments($purchase);

        if (Schema::hasTable('laboratory_purchase_items')) {
            $this->deleteModels($purchase->laboratoryPurchaseItems()->withTrashed()->get(), 'laboratory_purchase_items');
        }

        if (Schema::hasTable('laboratory_appointments')) {
            $appointment = $purchase->laboratoryAppointment()->withTrashed()->first();
            if ($appointment) {
                $this->deleteLaboratoryAppointment($appointment);
            }
        }

        $purchase->forceDelete();
        $this->increment('laboratory_purchases');
    }

    private function deleteOnlinePharmacyPurchase(?OnlinePharmacyPurchase $purchase): void
    {
        if (! $purchase) {
            return;
        }

        $this->deleteDevAssistanceRequests($purchase);
        $this->deleteInvoiceRecords($purchase);
        $this->deleteTransactionables($purchase);
        $this->detachVendorPayments($purchase);

        if (Schema::hasTable('online_pharmacy_purchase_items')) {
            $this->deleteModels($purchase->onlinePharmacyPurchaseItems()->withTrashed()->get(), 'online_pharmacy_purchase_items');
        }

        $purchase->forceDelete();
        $this->increment('online_pharmacy_purchases');
    }

    private function deleteMedicalAttentionSubscription(?MedicalAttentionSubscription $subscription): void
    {
        if (! $subscription) {
            return;
        }

        MedicalAttentionSubscription::query()
            ->where('parent_subscription_id', $subscription->id)
            ->pluck('id')
            ->each(fn (int $id) => $this->deleteMedicalAttentionSubscription(MedicalAttentionSubscription::withTrashed()->find($id)));

        $this->deleteTransactionables($subscription);
        $subscription->forceDelete();
        $this->increment('medical_attention_subscriptions');
    }

    private function deleteLaboratoryAppointment(?LaboratoryAppointment $appointment): void
    {
        if (! $appointment) {
            return;
        }

        if (Schema::hasTable('laboratory_appointment_interactions')) {
            DB::table('laboratory_appointment_interactions')
                ->where('laboratory_appointment_id', $appointment->id)
                ->delete();
            $this->increment('laboratory_appointment_interactions');
        }

        $appointment->forceDelete();
        $this->increment('laboratory_appointments');
    }

    private function deleteLaboratoryQuote(?LaboratoryQuote $quote): void
    {
        if (! $quote) {
            return;
        }

        if (Schema::hasTable('laboratory_notifications')) {
            LaboratoryNotification::query()->where('laboratory_quote_id', $quote->id)->delete();
        }

        if (Schema::hasTable('laboratory_quote_items')) {
            $this->deleteModels($quote->quoteItems()->get(), 'laboratory_quote_items');
        }
        $quote->forceDelete();
        $this->increment('laboratory_quotes');
    }

    private function deleteEfevooToken(?EfevooToken $token): void
    {
        if (! $token) {
            return;
        }

        if (Schema::hasTable('efevoo_transactions')) {
            EfevooTransaction::query()->where('efevoo_token_id', $token->id)->delete();
            $this->increment('efevoo_transactions');
        }
        $token->forceDelete();
        $this->increment('efevoo_tokens');
    }

    private function deleteDevAssistanceRequests(Model $purchase): void
    {
        if (! Schema::hasTable('dev_assistance_requests')) {
            return;
        }

        $purchase->devAssistanceRequests()->withTrashed()->each(function (DevAssistanceRequest $request) {
            if (Schema::hasTable('dev_assistance_comments')) {
                DevAssistanceComment::query()
                    ->where('dev_assistance_request_id', $request->id)
                    ->delete();
                $this->increment('dev_assistance_comments');
            }
            $request->forceDelete();
            $this->increment('dev_assistance_requests');
        });
    }

    private function deleteInvoiceRecords(Model $purchase): void
    {
        if (! Schema::hasTable('invoices') && ! Schema::hasTable('invoice_requests')) {
            return;
        }

        if (Schema::hasTable('invoices')) {
            $invoice = $purchase->invoice()->withTrashed()->first();
            if ($invoice) {
                $invoice->forceDelete();
                $this->increment('invoices');
            }
        }

        if (Schema::hasTable('invoice_requests')) {
            $invoiceRequest = $purchase->invoiceRequest()->withTrashed()->first();
            if ($invoiceRequest) {
                $invoiceRequest->forceDelete();
                $this->increment('invoice_requests');
            }
        }
    }

    private function deleteTransactionables(Model $model): void
    {
        if (! Schema::hasTable('transactionables') || ! Schema::hasTable('transactions')) {
            return;
        }

        $transactionIds = DB::table('transactionables')
            ->where('transactionable_type', $model->getMorphClass())
            ->where('transactionable_id', $model->id)
            ->pluck('transaction_id');

        DB::table('transactionables')
            ->where('transactionable_type', $model->getMorphClass())
            ->where('transactionable_id', $model->id)
            ->delete();

        foreach ($transactionIds as $transactionId) {
            $remaining = DB::table('transactionables')
                ->where('transaction_id', $transactionId)
                ->count();

            if ($remaining === 0) {
                Transaction::withTrashed()->where('id', $transactionId)->forceDelete();
                $this->increment('transactions');
            }
        }
    }

    private function detachVendorPayments(Model $purchase): void
    {
        if (! Schema::hasTable('vendor_payments') || ! Schema::hasTable('vendor_paymentables')) {
            return;
        }

        $purchase->vendorPayments()->each(function (VendorPayment $vendorPayment) use ($purchase) {
            $purchase->vendorPayments()->detach($vendorPayment->id);

            $stillLinked = DB::table('vendor_paymentables')
                ->where('vendor_payment_id', $vendorPayment->id)
                ->exists();

            if (! $stillLinked) {
                $vendorPayment->forceDelete();
                $this->increment('vendor_payments');
            }
        });
    }

    /**
     * @param  iterable<int, Model>  $models
     */
    private function deleteModels(iterable $models, string $counterKey): void
    {
        foreach ($models as $model) {
            $this->forceDeleteModel($model, $counterKey);
        }
    }

    private function forceDeleteModel(Model $model, string $counterKey): void
    {
        if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($model), true)) {
            $model->forceDelete();
        } else {
            $model->delete();
        }

        $this->increment($counterKey);
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return [];
    }

    private function increment(string $key, int $amount = 1): void
    {
        $this->deletedCounts[$key] = ($this->deletedCounts[$key] ?? 0) + $amount;
    }
}
