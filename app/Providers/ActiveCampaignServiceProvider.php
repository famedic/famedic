<?php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\ActiveCampaign\ActiveCampaignService;

// Observers
use App\Models\Contact;
use App\Observers\ContactObserver;
use App\Models\LaboratoryPurchase;
use App\Observers\LaboratoryPurchaseObserver;
use App\Models\OnlinePharmacyPurchase;
use App\Observers\OnlinePharmacyPurchaseObserver;
use App\Models\MedicalAttentionSubscription;
use App\Observers\MedicalAttentionSubscriptionObserver;
use App\Models\LaboratoryCartItem;
use App\Observers\LaboratoryCartItemObserver;
use App\Models\Invoice;
use App\Observers\InvoiceObserver;
use App\Models\LaboratoryNotification;
use App\Observers\LaboratoryNotificationObserver;
use App\Models\LaboratoryAppointment;
use App\Observers\LaboratoryAppointmentObserver;

class ActiveCampaignServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ActiveCampaignService::class, function ($app) {
            return new ActiveCampaignService();
        });
    }

    public function boot(): void
    {
        // Observers relacionados con ActiveCampaign
        Contact::observe(ContactObserver::class);
        LaboratoryPurchase::observe(LaboratoryPurchaseObserver::class);
        OnlinePharmacyPurchase::observe(OnlinePharmacyPurchaseObserver::class);
        MedicalAttentionSubscription::observe(MedicalAttentionSubscriptionObserver::class);
        LaboratoryCartItem::observe(LaboratoryCartItemObserver::class);
        Invoice::observe(InvoiceObserver::class);
        LaboratoryNotification::observe(LaboratoryNotificationObserver::class);
        LaboratoryAppointment::observe(LaboratoryAppointmentObserver::class);
    }
}