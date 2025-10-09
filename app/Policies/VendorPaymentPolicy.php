<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorPayment;

class VendorPaymentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.vendor-payments')
            || $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
    }

    public function view(User $user, VendorPayment $vendorPayment): bool
    {
        $isLaboratory = $vendorPayment->laboratoryPurchases()->exists();
        $isPharmacy = $vendorPayment->onlinePharmacyPurchases()->exists();

        if ($isLaboratory) {
            return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.vendor-payments');
        }

        if ($isPharmacy) {
            return $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
        }

        return false;
    }

    public function create(User $user): bool
    {
        return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.vendor-payments')
            || $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
    }

    public function update(User $user, VendorPayment $vendorPayment): bool
    {
        $isLaboratory = $vendorPayment->laboratoryPurchases()->exists();
        $isPharmacy = $vendorPayment->onlinePharmacyPurchases()->exists();

        if ($isLaboratory) {
            return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.vendor-payments');
        }

        if ($isPharmacy) {
            return $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
        }

        return false;
    }

    public function delete(User $user, VendorPayment $vendorPayment): bool
    {
        $isLaboratory = $vendorPayment->laboratoryPurchases()->exists();
        $isPharmacy = $vendorPayment->onlinePharmacyPurchases()->exists();

        if ($isLaboratory) {
            return $user->administrator?->hasPermissionTo('laboratory-purchases.manage.vendor-payments');
        }

        if ($isPharmacy) {
            return $user->administrator?->hasPermissionTo('online-pharmacy-purchases.manage.vendor-payments');
        }

        return false;
    }
}
