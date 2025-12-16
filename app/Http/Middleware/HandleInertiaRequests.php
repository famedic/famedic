<?php

namespace App\Http\Middleware;

use App\Enums\LaboratoryBrand;
use App\Services\Tracking\Tracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Middleware;
use Tighten\Ziggy\Ziggy;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user(),
            ],
            ...($request->user() ? [
                'medicalAttentionSubscriptionIsActive' => $request->user()->customer?->medical_attention_subscription_is_active,
                'formattedMedicalAttentionSubscriptionExpiresAt' => $request->user()->customer?->formatted_medical_attention_subscription_expires_at,
                'medicalAttentionIdentifier' => $request->user()->customer?->medical_attention_identifier,
                'hasOdessaAfiliateAccount' => $request->user()->customer?->has_odessa_afiliate_account,
            ] : []),
            'ziggy' => fn () => [
                ...(new Ziggy)->toArray(),
                'location' => $request->url(),
            ],
            'mainNavigation' => [
                [
                    'label' => 'Atención médica',
                    'url' => route('medical-attention'),
                    'current' => Route::currentRouteName() === 'medical-attention',
                ],
                [
                    'label' => 'Laboratorios',
                    'url' => route('laboratory-brand-selection'),
                    'current' => Route::currentRouteName() === 'laboratory-brand-selection' || Route::currentRouteName() === 'laboratory-stores.index' || Route::currentRouteName() === 'laboratory-tests' || Route::currentRouteName() === 'laboratory.shopping-cart',
                ],
                [
                    'label' => 'Farmacía en línea',
                    'url' => route('online-pharmacy'),
                    'current' => Route::currentRouteName() === 'online-pharmacy' || Route::currentRouteName() === 'online-pharmacy-search' || Route::currentRouteName() === 'online-pharmacy.shopping-cart',
                ],
            ],
            'userNavigation' => $request->user() ? $this->getUserNavigation((bool) $request->user()->administrator, (bool) $request->user()?->customer?->medical_attention_subscription_is_active) : [],
            'flashMessage' => session('flashMessage'),
            'trackingEvents' => function () {
                if (! app()->environment('production')) {
                    return [];
                }

                $trackingEvents = session()->pull('trackingEvents', []);

                if (
                    ! empty($trackingEvents) &&
                    config('services.facebook.pixel_id') &&
                    config('services.facebook.capi_token')
                ) {
                    $tracking = app(Tracking::class);
                    $tracking->propagateEvents($trackingEvents);

                    return collect($trackingEvents)
                        ->filter(fn ($e) => $e->sendToBrowser)
                        ->map(fn ($e) => $e->toBrowserPayload())
                        ->values()
                        ->all();
                }

                return [];
            },
            ...($request->user() ? ['laboratoryCarts' => $this->getLaboratoryCarts()] : []),
            'laboratoryBrands' => LaboratoryBrand::brandsData(),
            ...($request->user() ? ['onlinePharmacyCart' => $this->getOnlinePharmacyCart()] : []),
        ];
    }

    protected function getUserNavigation(bool $admin = false, bool $family = false)
    {
        $userNavigation = [
            [
                'label' => 'Mi cuenta',
                'url' => route('user.edit'),
                'icon' => 'UserCircleIcon',
                'current' => Route::currentRouteName() === 'user.edit',
            ],
            [
                'label' => 'Mis pedidos',
                'url' => route('laboratory-purchases.index'),
                'icon' => 'ShoppingBagIcon',
                'current' => Route::currentRouteName() === 'laboratory-purchases.index' || Route::currentRouteName() === 'laboratory-purchases.show' || Route::currentRouteName() === 'online-pharmacy-purchases.index' || Route::currentRouteName() === 'online-pharmacy-purchases.show',
            ],
            /*[
                'label' => 'Mis cotizaciones',
                'url' => route('laboratory-quotes.index'),
                'icon' => 'DocumentCheckIcon',
                'current' => Route::currentRouteName() === 'laboratory-quotes.index' || Route::currentRouteName() === 'laboratory-quotes.show' || Route::currentRouteName() === 'online-pharmacy-purchases.index' || Route::currentRouteName() === 'online-pharmacy-purchases.show',
            ],*/
            [
                'label' => 'Mis resultados',
                'url' => route('laboratory-results.index'),
                'icon' => 'BeakerIcon',
                'current' => Route::currentRouteName() === 'laboratory-results.index' || Route::currentRouteName() === 'laboratory-results.show' || Route::currentRouteName() === 'online-pharmacy-purchases.index' || Route::currentRouteName() === 'online-pharmacy-purchases.show',
            ],
            ...($family ? [
                [
                    'label' => 'Mi familia',
                    'url' => route('family.index'),
                    'icon' => 'UsersIcon',
                    'current' => Route::currentRouteName() === 'family.index' ||
                        Route::currentRouteName() === 'family.create' ||
                        Route::currentRouteName() === 'family.edit',
                ],
            ] : []),
            [
                'label' => 'Mis perfiles fiscales',
                'url' => route('tax-profiles.index'),
                'icon' => 'BuildingLibraryIcon',
                'current' => Route::currentRouteName() === 'tax-profiles.index',
            ],
            [
                'label' => 'Mis métodos de pago',
                'url' => route('payment-methods.index'),
                'icon' => 'CreditCardIcon',
                'current' => Route::currentRouteName() === 'payment-methods.index',
            ],
            [
                'label' => 'Mis direcciones',
                'url' => route('addresses.index'),
                'icon' => 'MapPinIcon',
                'current' => Route::currentRouteName() === 'addresses.index' ||
                    Route::currentRouteName() === 'addresses.create' ||
                    Route::currentRouteName() === 'addresses.edit',
            ],
            [
                'label' => 'Mis pacientes frecuentes',
                'url' => route('contacts.index'),
                'icon' => 'IdentificationIcon',
                'current' => Route::currentRouteName() === 'contacts.index' ||
                    Route::currentRouteName() === 'contacts.create' ||
                    Route::currentRouteName() === 'contacts.edit',
            ],
        ];

        if ($admin) {
            $userNavigation[] = [
                'label' => 'Administración',
                'url' => route('admin.admin'),
                'icon' => 'CommandLineIcon',
                'current' => Route::currentRouteName() === 'admin',
            ];
        }

        return $userNavigation;
    }

    protected function getLaboratoryCarts()
    {
        return array_combine(
            array_map(fn ($brand) => $brand->value, LaboratoryBrand::cases()),
            array_map(fn ($brand) => auth()->user()->customer?->laboratoryCartItems()->with('laboratoryTest')->ofBrand($brand)->get(), LaboratoryBrand::cases())
        );
    }

    protected function getOnlinePharmacyCart()
    {
        return auth()->user()->customer?->onlinePharmacyCartItems()->get();
    }
}
