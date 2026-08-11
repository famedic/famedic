<?php

namespace App\Services\Api\V1\Uat;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Data\Api\V1\Uat\AkubicaUatFixturePlan;
use App\Data\Api\V1\Uat\AkubicaUatFixtureResult;
use App\Enums\CouponApprovalStatus;
use App\Enums\CouponType;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Exceptions\AkubicaUatFixtureException;
use App\Models\Address;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\Api\V1\IdempotencyRecord;
use App\Models\Contact;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryCheckoutDraft;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryStore;
use App\Models\LaboratoryTest;
use App\Models\LaboratoryTestCategory;
use App\Models\OtpChallenge;
use App\Models\OtpDeliveryOperation;
use App\Models\OtpRateLimit;
use App\Models\OtpSecureDownloadLink;
use App\Models\OtpStepUpGrant;
use App\Models\RegularAccount;
use App\Models\TaxProfile;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

class AkubicaUatFixtureMaterializer
{
    private const STATUS_RESET = AkubicaUatFixtureContract::STATUS_RESETTING;

    private const RESERVED_NONEXISTENT_COUPON_HASH = 'reserved_nonexistent_coupon_hash';

    /**
     * @var list<array{method: string, path: string}>
     */
    private const IDEMPOTENCY_ALLOWED_OPERATIONS = [
        ['method' => 'POST', 'path' => 'api/v1/auth/login/request-code'],
        ['method' => 'POST', 'path' => 'api/v1/auth/register'],
        ['method' => 'POST', 'path' => 'api/v1/checkout/payment-link'],
        ['method' => 'POST', 'path' => 'api/v1/laboratory-appointments'],
        ['method' => 'POST', 'path' => 'api/v1/orders/{order_id}/invoices/{invoice_id}/step-up/request'],
        ['method' => 'POST', 'path' => 'api/v1/orders/{order_id}/invoices/{invoice_id}/secure-link'],
        ['method' => 'POST', 'path' => 'api/v1/orders/{order_id}/results/step-up/request'],
        ['method' => 'POST', 'path' => 'api/v1/orders/{order_id}/results/secure-link'],
        ['method' => 'POST', 'path' => 'api/v1/orders/{order_id}/invoice-request'],
    ];

    /**
     * @return array<string, string>
     */
    public function configuredStatus(): array
    {
        return (new AkubicaUatFixturePlanner())->configuredStatus();
    }

    public function buildPlan(string $action, bool $requireConfigured = true): AkubicaUatFixturePlan
    {
        return (new AkubicaUatFixturePlanner())->buildPlan($action, $requireConfigured);
    }

    public function assertNoCollisions(AkubicaUatFixturePlan $plan): void
    {
        $manifest = AkubicaUatFixtureManifest::query()
            ->where('namespace', $plan->namespace)
            ->first();

        if ($manifest !== null && (
            $manifest->namespace !== AkubicaUatFixtureContract::NAMESPACE
            || (int) $manifest->fixture_version !== AkubicaUatFixtureContract::FIXTURE_VERSION
            || ! in_array($manifest->status, AkubicaUatFixtureContract::ALLOWED_STATUSES, true)
            || ! is_array($manifest->metadata)
        )) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        if ($manifest !== null && in_array($manifest->status, [
            AkubicaUatFixtureContract::STATUS_PREPARING,
            AkubicaUatFixtureContract::STATUS_FAILED,
        ], true)) {
            $this->assertRecoverableManifest($manifest, $plan);
        }

        $identities = $this->requiredIdentities();
        $naturalKeys = $this->naturalKeys($plan->namespace);

        foreach (['primary', 'foreign'] as $role) {
            $email = $identities[$role]['email'];
            $phone = $identities[$role]['phone'];

            $user = User::query()
                ->where(function ($query) use ($email, $phone): void {
                    $query->where('email', $email)
                        ->orWhere('phone', $phone);
                })
                ->first();

            if ($user === null) {
                continue;
            }

            if ($manifest === null || (int) Arr::get($manifest->metadata, "ids.users.{$role}") !== (int) $user->id) {
                throw new AkubicaUatFixtureException('UAT_COLLISION_USER');
            }
        }

        $disposableExists = User::query()
            ->where('email', $identities['disposable']['email'])
            ->orWhere('phone', $identities['disposable']['phone'])
            ->exists();

        if ($disposableExists) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_DISPOSABLE_IDENTITY');
        }

        $catalogCollision = LaboratoryTestCategory::query()
            ->withTrashed()
            ->where('name', $naturalKeys['category_name'])
            ->first();

        if ($catalogCollision !== null && ($manifest === null || (int) Arr::get($manifest->metadata, 'ids.category') !== (int) $catalogCollision->id)) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_CATEGORY');
        }

        $storeCollision = LaboratoryStore::query()
            ->withTrashed()
            ->where('name', $naturalKeys['store_name'])
            ->where('brand', $naturalKeys['brand']->value)
            ->first();

        if ($storeCollision !== null && ($manifest === null || (int) Arr::get($manifest->metadata, 'ids.store') !== (int) $storeCollision->id)) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_STORE');
        }

        foreach ($naturalKeys['test_gda_ids'] as $slot => $gdaId) {
            $test = LaboratoryTest::query()->withTrashed()->where('gda_id', $gdaId)->where('brand', $naturalKeys['brand']->value)->first();
            if ($test !== null && ($manifest === null || (int) Arr::get($manifest->metadata, "ids.tests.{$slot}") !== (int) $test->id)) {
                throw new AkubicaUatFixtureException('UAT_COLLISION_TEST');
            }
        }

        foreach ($naturalKeys['coupon_codes'] as $slot => $code) {
            $coupon = Coupon::query()->where('code', $code)->first();
            if ($coupon !== null && ($manifest === null || (int) Arr::get($manifest->metadata, "ids.coupons.{$slot}") !== (int) $coupon->id)) {
                throw new AkubicaUatFixtureException('UAT_COLLISION_COUPON');
            }
        }

        foreach ([
            ['model' => Customer::class, 'metadata' => 'ids.customers', 'column' => 'user_id', 'values' => array_values((array) Arr::get($manifest?->metadata ?? [], 'ids.users', [])), 'code' => 'UAT_COLLISION_CUSTOMER'],
            ['model' => Contact::class, 'metadata' => 'ids.contacts', 'column' => 'name', 'values' => ['[UAT] Contacto primary', '[UAT] Contacto foreign'], 'code' => 'UAT_COLLISION_CONTACT'],
            ['model' => Address::class, 'metadata' => 'ids.addresses', 'column' => 'street', 'values' => ['[UAT] Avenida FAMEDIC'], 'code' => 'UAT_COLLISION_ADDRESS'],
            ['model' => TaxProfile::class, 'metadata' => 'ids.tax_profiles', 'column' => 'razon_social', 'values' => ['[UAT] FAMEDIC AKUBICA primary', '[UAT] FAMEDIC AKUBICA foreign'], 'code' => 'UAT_COLLISION_TAX_PROFILE'],
            ['model' => LaboratoryPurchase::class, 'metadata' => 'ids.purchases', 'column' => 'gda_order_id', 'values' => [
                strtoupper($plan->namespace).'-RESULT-READY',
                strtoupper($plan->namespace).'-RESULT-PENDING',
                strtoupper($plan->namespace).'-INVOICE-READY',
                strtoupper($plan->namespace).'-INVOICE-REQUEST',
                strtoupper($plan->namespace).'-FOREIGN-ORDER',
            ], 'code' => 'UAT_COLLISION_PURCHASE'],
        ] as $collisionRule) {
            if ($collisionRule['values'] === []) {
                continue;
            }

            $records = $collisionRule['model']::query()
                ->withTrashed()
                ->whereIn($collisionRule['column'], $collisionRule['values'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $manifestIds = array_map('intval', array_values((array) Arr::get($manifest?->metadata ?? [], $collisionRule['metadata'], [])));
            if (array_diff($records, $manifestIds) !== []) {
                throw new AkubicaUatFixtureException($collisionRule['code']);
            }
        }

        if ($manifest !== null) {
            $ids = (array) Arr::get($manifest->metadata, 'ids', []);
            $this->assertNoExternalReferences($ids);
        } else {
            $this->assertFirstApplyHasNoSyntheticCollisions($plan, $identities, $naturalKeys);
        }

        if ($manifest === null) {
            $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
            foreach ($plan->storagePaths as $path) {
                if ($disk->exists($path)) {
                    throw new AkubicaUatFixtureException('UAT_COLLISION_STORAGE');
                }
            }
        }
    }

    public function apply(AkubicaUatFixturePlan $plan): AkubicaUatFixtureResult
    {
        $storageWrites = [];
        $manifest = null;

        $this->neutralizeSideEffects();

        try {
            $result = LaboratoryTest::withoutSyncingToSearch(function () use ($plan, &$storageWrites, &$manifest): AkubicaUatFixtureResult {
                return Model::withoutEvents(function () use ($plan, &$storageWrites, &$manifest): AkubicaUatFixtureResult {
                    $storageWrites = $this->stageSyntheticDocuments($plan->namespace);
                    $this->maybeThrowTestingFault('after_temporaries');
                    $manifest = $this->beginPreparingManifest($plan, $storageWrites);
                    $this->maybeThrowTestingFault('after_preparing_manifest');

                    $counts = [
                        'created' => 0,
                        'updated' => 0,
                        'deleted' => 0,
                    ];

                    DB::transaction(function () use ($plan, &$counts, &$storageWrites, &$manifest): void {
                        $naturalKeys = $this->naturalKeys($plan->namespace);
                        $identities = $this->requiredIdentities();

                        $metadata = $manifest->metadata ?? [];
                        $ids = $metadata['ids'] ?? [];

                        $primaryUser = $this->upsertUser('primary', $identities['primary'], $ids, $counts);
                        $foreignUser = $this->upsertUser('foreign', $identities['foreign'], $ids, $counts);

                        $primaryCustomer = $this->upsertCustomer('primary', $primaryUser, $ids, $counts);
                        $foreignCustomer = $this->upsertCustomer('foreign', $foreignUser, $ids, $counts);

                        $primaryContact = $this->upsertContact('primary', $primaryCustomer, $ids, $counts);
                        $foreignContact = $this->upsertContact('foreign', $foreignCustomer, $ids, $counts);

                        $primaryAddress = $this->upsertAddress('primary', $primaryCustomer, $ids, $counts);
                        $foreignAddress = $this->upsertAddress('foreign', $foreignCustomer, $ids, $counts);

                        $primaryTaxProfile = $this->upsertTaxProfile('primary', $primaryCustomer, $plan->namespace, $ids, $counts);
                        $foreignTaxProfile = $this->upsertTaxProfile('foreign', $foreignCustomer, $plan->namespace, $ids, $counts);

                        $category = $this->upsertCategory($naturalKeys['category_name'], $ids, $counts);
                        $catalogTests = $this->upsertCatalogTests($category, $naturalKeys, $ids, $counts);
                        $store = $this->upsertStore($naturalKeys['store_name'], $naturalKeys['brand'], $ids, $counts);

                        $cartItems = $this->upsertCartItems($primaryCustomer, $catalogTests, $ids, $counts);
                        $checkoutDraft = $this->upsertCheckoutDraft($primaryCustomer, $primaryContact, $primaryAddress, $ids, $counts);

                        $coupons = $this->upsertCoupons($plan->namespace, $ids, $counts);
                        $couponAssignments = $this->upsertCouponAssignments($primaryUser, $coupons, $ids, $counts);

                        $documents = $this->storageDefinitions($plan->namespace);
                        $purchases = $this->upsertPurchases(
                            namespace: $plan->namespace,
                            primaryCustomer: $primaryCustomer,
                            foreignCustomer: $foreignCustomer,
                            primaryTaxProfile: $primaryTaxProfile,
                            foreignTaxProfile: $foreignTaxProfile,
                            catalogTests: $catalogTests,
                            coupons: $coupons,
                            ids: $ids,
                            counts: $counts,
                            storagePaths: array_keys($documents),
                        );

                        $activeMetadata = [
                                'ids' => [
                                    'users' => [
                                        'primary' => $primaryUser->id,
                                        'foreign' => $foreignUser->id,
                                    ],
                                    'regular_accounts' => [
                                        'primary' => $primaryCustomer->customerable_id,
                                        'foreign' => $foreignCustomer->customerable_id,
                                    ],
                                    'customers' => [
                                        'primary' => $primaryCustomer->id,
                                        'foreign' => $foreignCustomer->id,
                                    ],
                                    'contacts' => [
                                        'primary' => $primaryContact->id,
                                        'foreign' => $foreignContact->id,
                                    ],
                                    'addresses' => [
                                        'primary' => $primaryAddress->id,
                                        'foreign' => $foreignAddress->id,
                                    ],
                                    'tax_profiles' => [
                                        'primary' => $primaryTaxProfile->id,
                                        'foreign' => $foreignTaxProfile->id,
                                    ],
                                    'category' => $category->id,
                                    'tests' => [
                                        'olab' => $catalogTests['olab']->id,
                                        'swisslab' => $catalogTests['swisslab']->id,
                                    ],
                                    'store' => $store->id,
                                    'cart_items' => array_values($cartItems->pluck('id')->all()),
                                    'checkout_draft' => $checkoutDraft->id,
                                    'coupons' => [
                                        'valid' => $coupons['valid']->id,
                                        'used' => $coupons['used']->id,
                                        'not_applicable' => $coupons['not_applicable']->id,
                                    ],
                                    'coupon_assignments' => [
                                        'valid' => $couponAssignments['valid']->id,
                                        'used' => $couponAssignments['used']->id,
                                    ],
                                    'purchases' => collect($purchases)->mapWithKeys(fn ($model, $slot) => [$slot => $model->id])->all(),
                                    'purchase_items' => collect($purchases)
                                        ->mapWithKeys(fn ($model, $slot) => [$slot => $model->laboratoryPurchaseItems->pluck('id')->all()])
                                        ->all(),
                                    'invoices' => [
                                        'invoice_ready' => $purchases['invoice_ready']->invoice?->id,
                                        'foreign_order' => $purchases['foreign_order']->invoice?->id,
                                    ],
                                    'invoice_requests' => [
                                        'invoice_request_pending' => $purchases['invoice_request_pending']->invoiceRequest?->id,
                                        'foreign_order_invoice_request' => $purchases['foreign_order']->invoiceRequest?->id,
                                    ],
                                ],
                                'identity_hashes' => $plan->identityHashes,
                                'storage_hashes' => $plan->storageHashes,
                                'storage_paths' => $plan->storagePaths,
                                'natural_key_hashes' => $this->naturalKeyHashes($plan->namespace),
                                'logical_types' => [
                                    'invoice_requests' => [
                                        'invoice_request_pending' => 'invoice_request_pending',
                                        'foreign_order_invoice_request' => 'foreign_order_invoice_request',
                                    ],
                                ],
                                'parent_purchase_hashes' => [
                                    'invoice_requests' => [
                                        'invoice_request_pending' => $this->hashNaturalKey('purchase|'.strtoupper($plan->namespace).'-INVOICE-REQUEST'),
                                        'foreign_order_invoice_request' => $this->hashNaturalKey('purchase|'.strtoupper($plan->namespace).'-FOREIGN-ORDER'),
                                    ],
                                ],
                                'idempotency_record_ids' => $this->idempotencyRecordIdsFromMetadata((array) Arr::get($manifest->metadata, 'idempotency_record_ids', [])),
                                'idempotency_actor_hashes' => $plan->idempotencyActorHashes,
                                'idempotency_allowed_operations' => self::IDEMPOTENCY_ALLOWED_OPERATIONS,
                                'idempotency_window_started_at' => $manifest->created_at?->toIso8601String(),
                                'idempotency_window_ended_at' => $plan->expiresAt->toIso8601String(),
                                self::RESERVED_NONEXISTENT_COUPON_HASH => hash('sha256', $this->naturalKeys($plan->namespace)['reserved_nonexistent_coupon']),
                            ];

                        $manifest->forceFill([
                            'metadata' => array_merge((array) $manifest->metadata, $activeMetadata),
                        ])->save();
                        $this->maybeThrowTestingFault('after_resources_before_promotion');
                        $this->promoteSyntheticDocuments($storageWrites);
                        $this->maybeThrowTestingFault('after_promotion_before_hash');
                        $this->assertPromotedDocuments($storageWrites);
                        $this->maybeThrowTestingFault('after_hash_before_active');
                        $manifest->markActive(array_merge((array) $manifest->metadata, $activeMetadata));
                    });

                    return new AkubicaUatFixtureResult(
                        status: 'ok',
                        action: 'apply',
                        namespace: $plan->namespace,
                        counts: $counts,
                        details: [
                            'fixture_status' => AkubicaUatFixtureContract::STATUS_ACTIVE,
                            'fixture_expired' => false,
                            'plan_hash' => $plan->planHash(),
                        ],
                    );
                });
            });
        } catch (Throwable $throwable) {
            $this->cleanupStagedDocuments($storageWrites);
            if ($manifest instanceof AkubicaUatFixtureManifest && $manifest->exists && $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
                $this->markManifestFailedSafely($manifest);
            }
            throw $throwable;
        }

        return $result;
    }

    private function assertFirstApplyHasNoSyntheticCollisions(array|AkubicaUatFixturePlan $plan, array $identities, array $naturalKeys): void
    {
        $namespace = $plan instanceof AkubicaUatFixturePlan ? $plan->namespace : AkubicaUatFixtureContract::NAMESPACE;
        $customerIdentifiers = [
            $namespace.'-customer-primary',
            $namespace.'-customer-foreign',
        ];

        if (LaboratoryCartItem::query()->withTrashed()
            ->where(function ($query) use ($customerIdentifiers, $naturalKeys): void {
                $query->whereHas('customer', fn ($customerQuery) => $customerQuery->whereIn('medical_attention_identifier', $customerIdentifiers))
                    ->orWhereHas('laboratoryTest', fn ($testQuery) => $testQuery->whereIn('gda_id', array_values($naturalKeys['test_gda_ids'])));
            })
            ->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_CART_ITEM');
        }

        if (RegularAccount::query()->whereHas('customer', fn ($query) => $query->whereIn('medical_attention_identifier', $customerIdentifiers))->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_REGULAR_ACCOUNT');
        }

        if (Customer::query()->withTrashed()->whereIn('medical_attention_identifier', $customerIdentifiers)->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_CUSTOMER');
        }

        if (Contact::query()->withTrashed()->whereIn('name', ['[UAT] Contacto primary', '[UAT] Contacto foreign'])->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_CONTACT');
        }

        if (Address::query()->withTrashed()->where('street', '[UAT] Avenida FAMEDIC')->where('neighborhood', 'AKUBICA')->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_ADDRESS');
        }

        if (TaxProfile::query()->withTrashed()->whereIn('razon_social', ['[UAT] FAMEDIC AKUBICA primary', '[UAT] FAMEDIC AKUBICA foreign'])->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_TAX_PROFILE');
        }

        $purchaseIds = LaboratoryPurchase::query()->withTrashed()
            ->whereIn('gda_order_id', [
                strtoupper($namespace).'-RESULT-READY',
                strtoupper($namespace).'-RESULT-PENDING',
                strtoupper($namespace).'-INVOICE-READY',
                strtoupper($namespace).'-INVOICE-REQUEST',
                strtoupper($namespace).'-FOREIGN-ORDER',
            ])
            ->pluck('id')
            ->all();

        if ($purchaseIds !== []) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_PURCHASE');
        }

        if (LaboratoryPurchaseItem::query()->withTrashed()->whereIn('gda_id', array_values($naturalKeys['test_gda_ids']))->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_PURCHASE_ITEM');
        }

        if (Invoice::query()->withTrashed()->where(function ($query) use ($namespace): void {
            $query->whereIn('invoice', [
                AkubicaUatFixtureContract::storagePath('invoices/invoice-ready.pdf'),
                AkubicaUatFixtureContract::storagePath('invoices/foreign-order.pdf'),
            ])->orWhereIn('invoice_xml', [
                AkubicaUatFixtureContract::storagePath('invoices/invoice-ready.xml'),
                AkubicaUatFixtureContract::storagePath('invoices/foreign-order.xml'),
            ]);
        })->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_INVOICE');
        }

        if (InvoiceRequest::query()->withTrashed()->where('fiscal_certificate', AkubicaUatFixtureContract::storagePath('tax/fiscal-certificate.pdf'))->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_INVOICE_REQUEST');
        }

        if (LaboratoryCheckoutDraft::query()->whereHas('customer', fn ($query) => $query->whereIn('medical_attention_identifier', $customerIdentifiers))->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_CHECKOUT_DRAFT');
        }

        if (CouponUser::query()->whereHas('coupon', fn ($query) => $query->whereIn('code', array_values($naturalKeys['coupon_codes'])))->exists()) {
            throw new AkubicaUatFixtureException('UAT_COLLISION_COUPON_ASSIGNMENT');
        }
    }

    /**
     * @param  array<string, array{content: string, temporary_path: ?string, final_path: string, mime: string}>  $storageWrites
     */
    private function beginPreparingManifest(AkubicaUatFixturePlan $plan, array $storageWrites): AkubicaUatFixtureManifest
    {
        return DB::transaction(function () use ($plan, $storageWrites): AkubicaUatFixtureManifest {
            $manifest = AkubicaUatFixtureManifest::query()->firstOrNew(['namespace' => $plan->namespace]);

            if ($manifest->exists && ! $manifest->canRecoverPreparing() && $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
                throw new AkubicaUatFixtureException('UAT_MANIFEST_NOT_RECOVERABLE');
            }

            $manifest->forceFill([
                'fixture_version' => $plan->fixtureVersion,
                'status' => AkubicaUatFixtureContract::STATUS_PREPARING,
                'expires_at' => $plan->expiresAt,
                'metadata' => array_merge((array) $manifest->metadata, [
                    'run_id_hash' => hash('sha256', (string) Str::uuid()),
                    'storage_hashes' => $plan->storageHashes,
                    'storage_paths' => array_values(array_map(function (string $path): string {
                        $this->assertAllowedStoragePath($path);

                        return $path;
                    }, $plan->storagePaths)),
                    'temporary_paths' => collect($storageWrites)
                        ->pluck('temporary_path')
                        ->filter()
                        ->map(fn (string $path): string => Str::startsWith($path, AkubicaUatFixtureContract::STORAGE_PREFIX.'.tmp/') ? $path : throw new AkubicaUatFixtureException('UAT_STORAGE_PATH_NOT_ALLOWED'))
                        ->values()
                        ->all(),
                    'natural_key_hashes' => $this->naturalKeyHashes($plan->namespace),
                    'ids' => (array) Arr::get($manifest->metadata, 'ids', []),
                    'idempotency_record_ids' => $this->idempotencyRecordIdsFromMetadata((array) Arr::get($manifest->metadata, 'idempotency_record_ids', [])),
                    'idempotency_actor_hashes' => $plan->idempotencyActorHashes,
                    'idempotency_allowed_operations' => self::IDEMPOTENCY_ALLOWED_OPERATIONS,
                    'idempotency_window_started_at' => now()->toIso8601String(),
                    'idempotency_window_ended_at' => $plan->expiresAt->toIso8601String(),
                ]),
            ])->save();

            return $manifest->fresh();
        });
    }

    private function assertRecoverableManifest(AkubicaUatFixtureManifest $manifest, AkubicaUatFixturePlan $plan): void
    {
        if (! $manifest->canRecoverPreparing()) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_NOT_RECOVERABLE');
        }

        $metadata = (array) $manifest->metadata;

        if (
            ! is_string(Arr::get($metadata, 'run_id_hash'))
            || (array) Arr::get($metadata, 'storage_hashes', []) !== $plan->storageHashes
            || (array) Arr::get($metadata, 'natural_key_hashes', []) !== $this->naturalKeyHashes($plan->namespace)
        ) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        foreach ((array) Arr::get($metadata, 'storage_paths', []) as $path) {
            $this->assertAllowedStoragePath((string) $path);
            if ($disk->exists($path) && hash('sha256', (string) $disk->get($path)) !== Arr::get($metadata, "storage_hashes.{$path}")) {
                throw new AkubicaUatFixtureException('UAT_STORAGE_HASH_MISMATCH');
            }
        }

        $ids = (array) Arr::get($metadata, 'ids', []);
        if ($ids !== []) {
            $this->assertRecoverableIds($ids, $plan);
        }
    }

    private function assertRecoverableIds(array $ids, AkubicaUatFixturePlan $plan): void
    {
        foreach ((array) Arr::get($ids, 'purchases', []) as $slot => $purchaseId) {
            if (! is_numeric($purchaseId)) {
                throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
            }

            $purchase = LaboratoryPurchase::query()->withTrashed()->find((int) $purchaseId);
            $expected = match ((string) $slot) {
                'results_ready' => strtoupper($plan->namespace).'-RESULT-READY',
                'results_pending' => strtoupper($plan->namespace).'-RESULT-PENDING',
                'invoice_ready' => strtoupper($plan->namespace).'-INVOICE-READY',
                'invoice_request_pending' => strtoupper($plan->namespace).'-INVOICE-REQUEST',
                'foreign_order' => strtoupper($plan->namespace).'-FOREIGN-ORDER',
                default => null,
            };

            if ($expected === null || $purchase === null || $purchase->gda_order_id !== $expected) {
                throw new AkubicaUatFixtureException('UAT_MANIFEST_NOT_RECOVERABLE');
            }
        }
    }

    private function markManifestFailedSafely(AkubicaUatFixtureManifest $manifest): void
    {
        try {
            $fresh = $manifest->fresh();
            if ($fresh instanceof AkubicaUatFixtureManifest && $fresh->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
                $fresh->markFailed();
            }
        } catch (Throwable) {
            // Preserve the original failure path; never print or serialize sensitive context here.
        }
    }

    private function maybeThrowTestingFault(string $point): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        if (in_array($point, (array) config('akubica_uat.testing_faults', []), true)) {
            throw new AkubicaUatFixtureException('UAT_TESTING_FAULT_'.$point);
        }
    }

    public function reset(AkubicaUatFixturePlan $plan): AkubicaUatFixtureResult
    {
        $this->neutralizeSideEffects();

        return LaboratoryTest::withoutSyncingToSearch(function () use ($plan): AkubicaUatFixtureResult {
            return Model::withoutEvents(function () use ($plan): AkubicaUatFixtureResult {
                $counts = [
                    'created' => 0,
                    'updated' => 0,
                    'deleted' => 0,
                ];
                $shouldDeleteStorage = false;
                $idempotencyCleanup = [
                    'status' => 'not_recorded',
                    'record_ids' => [],
                ];

                DB::transaction(function () use ($plan, &$counts, &$shouldDeleteStorage, &$idempotencyCleanup): void {
                    $manifest = AkubicaUatFixtureManifest::query()
                        ->where('namespace', $plan->namespace)
                        ->first();

                    if ($manifest === null) {
                        return;
                    }

                    $this->assertManifestIsResettable($manifest, $plan);
                    $idempotencyCleanup = $this->assertResettableIdempotencyRecords($manifest);
                    $shouldDeleteStorage = true;

                    $ids = (array) Arr::get($manifest->metadata, 'ids', []);

                    $this->deleteVerifiedRecords(PersonalAccessToken::query(), [], $counts, fn ($query) => $query
                        ->where('tokenable_type', User::class)
                        ->whereIn('tokenable_id', $this->userIdsFromMetadata($ids)));
                    $this->deleteVerifiedRecords(OtpSecureDownloadLink::query(), [], $counts, fn ($query) => $query->whereIn('user_id', $this->userIdsFromMetadata($ids)));
                    $this->deleteVerifiedRecords(OtpStepUpGrant::query(), [], $counts, fn ($query) => $query->whereIn('user_id', $this->userIdsFromMetadata($ids)));
                    $challengeIds = OtpChallenge::query()
                        ->whereIn('user_id', $this->userIdsFromMetadata($ids))
                        ->pluck('id')
                        ->all();
                    $this->deleteVerifiedRecords(OtpDeliveryOperation::query(), [], $counts, fn ($query) => $query->whereIn('otp_challenge_id', $challengeIds));
                    $this->deleteVerifiedRecords(OtpRateLimit::query(), [], $counts, fn ($query) => $query->whereIn('last_challenge_id', $challengeIds));
                    $this->deleteVerifiedRecords(OtpChallenge::query(), $challengeIds, $counts);
                    $this->deleteVerifiedIdempotencyRecords($idempotencyCleanup, $counts);

                    $this->deleteVerifiedRecords(CouponUser::query(), (array) Arr::get($ids, 'coupon_assignments', []), $counts);
                    $this->deleteVerifiedRecords(Invoice::query()->withTrashed(), array_filter((array) Arr::get($ids, 'invoices', [])), $counts);
                    $this->deleteVerifiedRecords(InvoiceRequest::query()->withTrashed(), array_filter((array) Arr::get($ids, 'invoice_requests', [])), $counts);

                    foreach ((array) Arr::get($ids, 'purchase_items', []) as $purchaseItemIds) {
                        $this->deleteVerifiedRecords(LaboratoryPurchaseItem::query()->withTrashed(), (array) $purchaseItemIds, $counts);
                    }

                    $this->deleteVerifiedRecords(LaboratoryPurchase::query()->withTrashed(), array_values((array) Arr::get($ids, 'purchases', [])), $counts);
                    $this->deleteVerifiedRecords(LaboratoryCheckoutDraft::query(), array_filter([(int) Arr::get($ids, 'checkout_draft')]), $counts);
                    $this->deleteVerifiedRecords(LaboratoryCartItem::query()->withTrashed(), (array) Arr::get($ids, 'cart_items', []), $counts);
                    $this->deleteVerifiedRecords(Coupon::query(), array_values((array) Arr::get($ids, 'coupons', [])), $counts);
                    $this->deleteVerifiedRecords(LaboratoryStore::query()->withTrashed(), array_filter([(int) Arr::get($ids, 'store')]), $counts);
                    $this->deleteVerifiedRecords(LaboratoryTest::query()->withTrashed(), array_values((array) Arr::get($ids, 'tests', [])), $counts);
                    $this->deleteVerifiedRecords(LaboratoryTestCategory::query()->withTrashed(), array_filter([(int) Arr::get($ids, 'category')]), $counts);
                    $this->deleteVerifiedRecords(TaxProfile::query()->withTrashed(), array_values((array) Arr::get($ids, 'tax_profiles', [])), $counts);
                    $this->deleteVerifiedRecords(Address::query()->withTrashed(), array_values((array) Arr::get($ids, 'addresses', [])), $counts);
                    $this->deleteVerifiedRecords(Contact::query()->withTrashed(), array_values((array) Arr::get($ids, 'contacts', [])), $counts);
                    $this->deleteVerifiedRecords(Customer::query()->withTrashed(), array_values((array) Arr::get($ids, 'customers', [])), $counts);
                    $this->deleteVerifiedRecords(RegularAccount::query()->withTrashed(), array_values((array) Arr::get($ids, 'regular_accounts', [])), $counts);
                    $this->deleteVerifiedRecords(User::query(), array_values((array) Arr::get($ids, 'users', [])), $counts);

                    $manifest->beginReset();
                    $manifest->delete();
                    $counts['deleted']++;
                });

                if ($shouldDeleteStorage) {
                    $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
                    foreach ($plan->storagePaths as $path) {
                        if ($disk->exists($path)) {
                            $disk->delete($path);
                            $counts['deleted']++;
                        }
                    }
                }

                return new AkubicaUatFixtureResult(
                    status: 'ok',
                    action: 'reset',
                    namespace: $plan->namespace,
                    counts: $counts,
                    details: [
                        'fixture_status' => self::STATUS_RESET,
                        'plan_hash' => $plan->planHash(),
                        'idempotency_cleanup' => $idempotencyCleanup['status'],
                        'idempotency_records' => count($idempotencyCleanup['record_ids']),
                    ],
                );
            });
        });
    }

    /**
     * @return array<string, array{email: string, phone: string, country: string}>
     */
    public function requiredIdentities(): array
    {
        $identities = $this->optionalIdentities();

        if (count($identities) !== 3) {
            throw new AkubicaUatFixtureException('UAT_CONFIG_REQUIRED');
        }

        return $identities;
    }

    /**
     * @return array<string, array{email: string, phone: string, country: string}>
     */
    private function optionalIdentities(): array
    {
        $identities = (array) config('akubica_uat.identities', []);

        $normalized = [];

        foreach (['primary', 'foreign', 'disposable'] as $role) {
            $identity = (array) ($identities[$role] ?? []);
            $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
            $phone = preg_replace('/\D+/', '', (string) ($identity['phone'] ?? '')) ?? '';
            $country = strtoupper(trim((string) ($identity['country'] ?? '')));

            if ($email === '' || $phone === '' || $country === '') {
                continue;
            }

            if ($country !== 'MX' || strlen($phone) !== 10) {
                throw new AkubicaUatFixtureException('UAT_CONFIG_INVALID');
            }

            $normalized[$role] = [
                'email' => $email,
                'phone' => $phone,
                'country' => $country,
            ];
        }

        $pairs = collect($normalized)
            ->flatMap(fn (array $identity): array => [$identity['email'], $identity['phone']])
            ->all();

        if (count($pairs) !== count(array_unique($pairs))) {
            throw new AkubicaUatFixtureException('UAT_CONFIG_COLLISION');
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function naturalKeys(string $namespace): array
    {
        return [
            'brand' => LaboratoryBrand::OLAB,
            'customer_identifiers' => [
                'primary' => $namespace.'-customer-primary',
                'foreign' => $namespace.'-customer-foreign',
            ],
            'category_name' => '[UAT] Akubica UAT v1 - Laboratorio',
            'store_name' => '[UAT] Akubica UAT v1 - Sucursal',
            'test_gda_ids' => [
                'olab' => strtoupper($namespace).'-OLAB',
                'swisslab' => strtoupper($namespace).'-SWISSLAB',
            ],
            'coupon_codes' => [
                'valid' => 'AKUATV1VALID',
                'used' => 'AKUATV1USED',
                'not_applicable' => 'AKUATV1NA',
            ],
            'reserved_nonexistent_coupon' => 'AKUATV1MISSING',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function naturalKeyHashes(string $namespace): array
    {
        $keys = $this->naturalKeys($namespace);

        return [
            'customers' => collect($keys['customer_identifiers'])->map(fn (string $value): string => $this->hashNaturalKey('customer|'.$value))->all(),
            'category' => $this->hashNaturalKey('category|'.$keys['category_name']),
            'store' => $this->hashNaturalKey('store|'.$keys['store_name'].'|'.$keys['brand']->value),
            'tests' => collect($keys['test_gda_ids'])->map(fn (string $value): string => $this->hashNaturalKey('test|'.$value))->all(),
            'coupons' => collect($keys['coupon_codes'])->map(fn (string $value): string => $this->hashNaturalKey('coupon|'.$value))->all(),
            'purchases' => [
                'results_ready' => $this->hashNaturalKey('purchase|'.strtoupper($namespace).'-RESULT-READY'),
                'results_pending' => $this->hashNaturalKey('purchase|'.strtoupper($namespace).'-RESULT-PENDING'),
                'invoice_ready' => $this->hashNaturalKey('purchase|'.strtoupper($namespace).'-INVOICE-READY'),
                'invoice_request_pending' => $this->hashNaturalKey('purchase|'.strtoupper($namespace).'-INVOICE-REQUEST'),
                'foreign_order' => $this->hashNaturalKey('purchase|'.strtoupper($namespace).'-FOREIGN-ORDER'),
            ],
            'invoice_requests' => [
                'invoice_request_pending' => $this->hashNaturalKey('invoice_request|'.strtoupper($namespace).'-INVOICE-REQUEST'),
                'foreign_order_invoice_request' => $this->hashNaturalKey('invoice_request|'.strtoupper($namespace).'-FOREIGN-ORDER'),
            ],
        ];
    }

    private function hashNaturalKey(string $value): string
    {
        return hash_hmac('sha256', $value, 'akubica-uat-natural-key');
    }

    /**
     * @return array<string, array{content: string, temporary_path: string, final_path: string, mime: string}>
     */
    private function stageSyntheticDocuments(string $namespace): array
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);
        $definitions = $this->storageDefinitions($namespace);
        $writes = [];
        $runId = (string) Str::uuid();

        foreach ($definitions as $finalPath => $definition) {
            $this->assertAllowedStoragePath($finalPath);

            $temporaryPath = AkubicaUatFixtureContract::STORAGE_PREFIX.'.tmp/'.$runId.'/'.Str::after($finalPath, AkubicaUatFixtureContract::STORAGE_PREFIX);
            if ($disk->exists($finalPath)) {
                $existing = (string) $disk->get($finalPath);

                if (hash('sha256', $existing) !== hash('sha256', $definition['content'])) {
                    throw new AkubicaUatFixtureException('UAT_COLLISION_STORAGE');
                }

                $writes[$finalPath] = [
                    'content' => $definition['content'],
                    'temporary_path' => null,
                    'final_path' => $finalPath,
                    'mime' => $definition['mime'],
                ];

                continue;
            }

            $disk->put($temporaryPath, $definition['content']);

            if (str_ends_with($finalPath, '.pdf') && ! str_starts_with($definition['content'], '%PDF')) {
                throw new AkubicaUatFixtureException('UAT_INVALID_SYNTHETIC_PDF');
            }

            $writes[$finalPath] = [
                'content' => $definition['content'],
                'temporary_path' => $temporaryPath,
                'final_path' => $finalPath,
                'mime' => $definition['mime'],
            ];
        }

        return $writes;
    }

    /**
     * @param  array<string, array{content: string, temporary_path: ?string, final_path: string, mime: string}>  $writes
     */
    private function promoteSyntheticDocuments(array $writes): void
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);

        foreach ($writes as $write) {
            if ($write['temporary_path'] === null) {
                continue;
            }

            $disk->move($write['temporary_path'], $write['final_path']);
        }
    }

    /**
     * @param  array<string, array{content: string, temporary_path: ?string, final_path: string, mime: string}>  $writes
     */
    private function assertPromotedDocuments(array $writes): void
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);

        foreach ($writes as $write) {
            $this->assertAllowedStoragePath($write['final_path']);

            if (! $disk->exists($write['final_path'])) {
                throw new AkubicaUatFixtureException('UAT_STORAGE_PROMOTION_FAILED');
            }

            if (hash('sha256', (string) $disk->get($write['final_path'])) !== hash('sha256', $write['content'])) {
                throw new AkubicaUatFixtureException('UAT_STORAGE_HASH_MISMATCH');
            }
        }
    }

    /**
     * @param  array<string, array{content: string, temporary_path: ?string, final_path: string, mime: string}>  $writes
     */
    private function cleanupStagedDocuments(array $writes): void
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);

        foreach ($writes as $write) {
            if ($write['temporary_path'] !== null && $disk->exists($write['temporary_path'])) {
                $disk->delete($write['temporary_path']);
            }

            if ($write['temporary_path'] !== null && $disk->exists($write['final_path'])) {
                $current = (string) $disk->get($write['final_path']);
                if (hash('sha256', $current) === hash('sha256', $write['content'])) {
                    $disk->delete($write['final_path']);
                }
            }
        }
    }

    private function assertAllowedStoragePath(string $path): void
    {
        $relative = Str::after($path, AkubicaUatFixtureContract::STORAGE_PREFIX);

        if (
            ! str_starts_with($path, AkubicaUatFixtureContract::STORAGE_PREFIX)
            || str_starts_with($path, '/')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || ! in_array($relative, AkubicaUatFixtureContract::STORAGE_DOCUMENTS, true)
        ) {
            throw new AkubicaUatFixtureException('UAT_STORAGE_PATH_NOT_ALLOWED');
        }
    }

    /**
     * @return array<string, array{content: string, mime: string}>
     */
    private function storageDefinitions(string $namespace): array
    {
        $prefix = AkubicaUatFixtureContract::STORAGE_PREFIX;

        return [
            $prefix.'results/result-ready.pdf' => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] results ready {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            $prefix.'results/foreign-order.pdf' => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] foreign results {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            $prefix.'invoices/invoice-ready.pdf' => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] invoice ready {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            $prefix.'invoices/invoice-ready.xml' => [
                'content' => '<invoice synthetic="true" namespace="'.$namespace.'"><status>completed</status></invoice>',
                'mime' => 'application/xml',
            ],
            $prefix.'invoices/foreign-order.pdf' => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] foreign invoice {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            $prefix.'invoices/foreign-order.xml' => [
                'content' => '<invoice synthetic="true" namespace="'.$namespace.'"><status>foreign</status></invoice>',
                'mime' => 'application/xml',
            ],
            $prefix.'tax/fiscal-certificate.pdf' => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] fiscal certificate {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
        ];
    }

    private function identityHash(string $email, string $phone): string
    {
        return hash_hmac('sha256', $email.'|'.$phone, (string) config('app.key'));
    }

    private function idempotencyActorHash(string $role, string $email, string $phone): string
    {
        return hash_hmac('sha256', $role.'|'.$email.'|'.$phone, 'akubica-uat-idempotency');
    }

    private function neutralizeSideEffects(): void
    {
        Bus::fake();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        Http::preventStrayRequests();

        config()->set('otp.p0a.delivery.driver', 'fake');
        config()->set('otp.p0a.flags.sms_delivery_enabled', false);
        config()->set('services.activecampaign.enabled', false);
        config()->set('services.activecampaign.coupons_enabled', false);
        config()->set('scout.driver', 'null');
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertUser(string $role, array $identity, array &$ids, array &$counts): User
    {
        $userId = (int) Arr::get($ids, "users.{$role}", 0);
        $attributes = [
            'name' => '[UAT] FAMEDIC',
            'paternal_lastname' => Str::upper($role),
            'maternal_lastname' => 'AKUBICA',
            'email' => $identity['email'],
            'phone' => $identity['phone'],
            'phone_country' => $identity['country'],
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'password' => Hash::make('UAT-fixture-password!'),
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'documentation_accepted_at' => now(),
        ];

        if ($userId > 0 && ($user = User::query()->find($userId))) {
            $user->fill($attributes);
            $user->save();
            $counts['updated']++;

            return $user->fresh();
        }

        $user = User::query()->create($attributes);
        $counts['created']++;

        return $user;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertCustomer(string $role, User $user, array &$ids, array &$counts): Customer
    {
        $customerId = (int) Arr::get($ids, "customers.{$role}", 0);
        $regularAccountId = (int) Arr::get($ids, "regular_accounts.{$role}", 0);
        $naturalKeys = $this->naturalKeys(AkubicaUatFixtureContract::NAMESPACE);

        if ($regularAccountId > 0 && ($account = RegularAccount::query()->withTrashed()->find($regularAccountId))) {
            if ($account->trashed()) {
                $account->restore();
            }
        } else {
            $account = RegularAccount::query()->create([]);
            $counts['created']++;
        }

        if ($customerId > 0 && ($customer = Customer::query()->withTrashed()->find($customerId))) {
            if ($customer->trashed()) {
                $customer->restore();
            }
            $customer->fill([
                'user_id' => $user->id,
                'medical_attention_identifier' => $naturalKeys['customer_identifiers'][$role],
                'customerable_type' => RegularAccount::class,
                'customerable_id' => $account->id,
                'medical_attention_subscription_expires_at' => now()->addDays(30),
            ]);
            $customer->save();
            $counts['updated']++;

            return $customer->fresh();
        }

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'medical_attention_identifier' => $naturalKeys['customer_identifiers'][$role],
            'customerable_type' => RegularAccount::class,
            'customerable_id' => $account->id,
            'medical_attention_subscription_expires_at' => now()->addDays(30),
        ]);
        $counts['created']++;

        return $customer;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertContact(string $role, Customer $customer, array &$ids, array &$counts): Contact
    {
        $contactId = (int) Arr::get($ids, "contacts.{$role}", 0);
        $attributes = [
            'customer_id' => $customer->id,
            'name' => '[UAT] Contacto '.$role,
            'paternal_lastname' => Str::upper($role),
            'maternal_lastname' => 'AKUBICA',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'phone' => $customer->user->phone,
            'phone_country' => $customer->user->phone_country,
        ];

        if ($contactId > 0 && ($contact = Contact::query()->withTrashed()->find($contactId))) {
            if ($contact->trashed()) {
                $contact->restore();
            }
            $contact->fill($attributes);
            $contact->save();
            $counts['updated']++;

            return $contact->fresh();
        }

        $contact = Contact::query()->create($attributes);
        $counts['created']++;

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertAddress(string $role, Customer $customer, array &$ids, array &$counts): Address
    {
        $addressId = (int) Arr::get($ids, "addresses.{$role}", 0);
        $attributes = [
            'customer_id' => $customer->id,
            'street' => '[UAT] Avenida FAMEDIC',
            'number' => $role === 'primary' ? '100' : '200',
            'neighborhood' => 'AKUBICA',
            'city' => 'Monterrey',
            'state' => 'Nuevo Leon',
            'zipcode' => '64000',
            'additional_references' => '[UAT] referencia sintetica',
        ];

        if ($addressId > 0 && ($address = Address::query()->withTrashed()->find($addressId))) {
            if ($address->trashed()) {
                $address->restore();
            }
            $address->fill($attributes);
            $address->save();
            $counts['updated']++;

            return $address->fresh();
        }

        $address = Address::query()->create($attributes);
        $counts['created']++;

        return $address;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertTaxProfile(string $role, Customer $customer, string $namespace, array &$ids, array &$counts): TaxProfile
    {
        $taxProfileId = (int) Arr::get($ids, "tax_profiles.{$role}", 0);
        $certificatePath = (string) array_key_first(array_filter(
            $this->storageDefinitions($namespace),
            fn (array $definition, string $path): bool => str_contains($path, 'fiscal-certificate'),
            ARRAY_FILTER_USE_BOTH
        ));

        $attributes = [
            'customer_id' => $customer->id,
            'name' => '[UAT] Razon Fiscal',
            'razon_social' => '[UAT] FAMEDIC AKUBICA '.$role,
            'rfc' => $role === 'primary' ? 'XAXX010101000' : 'XEXX010101000',
            'zipcode' => '64000',
            'codigo_postal_original' => '64000',
            'tax_regime' => '601',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => $certificatePath,
            'tipo_persona' => 'moral',
            'is_default' => true,
        ];

        if ($taxProfileId > 0 && ($taxProfile = TaxProfile::query()->withTrashed()->find($taxProfileId))) {
            if ($taxProfile->trashed()) {
                $taxProfile->restore();
            }
            $taxProfile->forceFill($attributes);
            $taxProfile->save();
            $counts['updated']++;

            return $taxProfile->fresh();
        }

        $taxProfile = new TaxProfile();
        $taxProfile->forceFill($attributes);
        $taxProfile->save();
        $counts['created']++;

        return $taxProfile;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertCategory(string $name, array &$ids, array &$counts): LaboratoryTestCategory
    {
        $categoryId = (int) Arr::get($ids, 'category', 0);

        if ($categoryId > 0 && ($category = LaboratoryTestCategory::query()->withTrashed()->find($categoryId))) {
            if ($category->trashed()) {
                $category->restore();
            }
            $category->name = $name;
            $category->save();
            $counts['updated']++;

            return $category->fresh();
        }

        $category = LaboratoryTestCategory::query()->create(['name' => $name]);
        $counts['created']++;

        return $category;
    }

    /**
     * @param  array<string, mixed>  $naturalKeys
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     * @return array<string, LaboratoryTest>
     */
    private function upsertCatalogTests(LaboratoryTestCategory $category, array $naturalKeys, array &$ids, array &$counts): array
    {
        $definitions = [
            'olab' => [
                'brand' => LaboratoryBrand::OLAB->value,
                'gda_id' => $naturalKeys['test_gda_ids']['olab'],
                'name' => '[UAT] Quimica 6 Elementos',
                'other_name' => '[UAT] Q6',
                'description' => '[UAT] estudio sintetico',
                'indications' => '[UAT] ayuno de 8 horas',
                'elements' => 'glucosa, urea',
                'common_use' => '[UAT] regression',
                'laboratory_test_category_id' => $category->id,
                'famedic_price_cents' => 35000,
                'public_price_cents' => 42000,
                'requires_appointment' => false,
                'feature_list' => ['synthetic' => true, 'namespace' => AkubicaUatFixtureContract::NAMESPACE],
            ],
            'swisslab' => [
                'brand' => LaboratoryBrand::OLAB->value,
                'gda_id' => $naturalKeys['test_gda_ids']['swisslab'],
                'name' => '[UAT] Perfil Tiroideo',
                'other_name' => '[UAT] TSH',
                'description' => '[UAT] estudio sintetico con cita',
                'indications' => '[UAT] sin indicaciones clinicas reales',
                'elements' => 'tsh,t3,t4',
                'common_use' => '[UAT] regression',
                'laboratory_test_category_id' => $category->id,
                'famedic_price_cents' => 48000,
                'public_price_cents' => 52000,
                'requires_appointment' => true,
                'feature_list' => ['synthetic' => true, 'namespace' => AkubicaUatFixtureContract::NAMESPACE],
            ],
        ];

        $tests = [];

        foreach ($definitions as $slot => $attributes) {
            $testId = (int) Arr::get($ids, "tests.{$slot}", 0);

            if ($testId > 0 && ($test = LaboratoryTest::query()->withTrashed()->find($testId))) {
                if ($test->trashed()) {
                    $test->restore();
                }
                $test->fill($attributes);
                $test->save();
                $counts['updated']++;
                $tests[$slot] = $test->fresh();
                continue;
            }

            $test = LaboratoryTest::query()->create($attributes);
            $counts['created']++;
            $tests[$slot] = $test;
        }

        return $tests;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertStore(string $name, LaboratoryBrand $brand, array &$ids, array &$counts): LaboratoryStore
    {
        $storeId = (int) Arr::get($ids, 'store', 0);
        $attributes = [
            'name' => $name,
            'brand' => $brand->value,
            'state' => 'Nuevo Leon',
            'address' => '[UAT] direccion sintetica',
            'weekly_hours' => '08:00-18:00',
            'saturday_hours' => '08:00-14:00',
            'sunday_hours' => 'cerrado',
            'google_maps_url' => 'https://example.invalid/uat-store',
        ];

        if ($storeId > 0 && ($store = LaboratoryStore::query()->withTrashed()->find($storeId))) {
            if ($store->trashed()) {
                $store->restore();
            }
            $store->fill($attributes);
            $store->save();
            $counts['updated']++;

            return $store->fresh();
        }

        $store = LaboratoryStore::query()->create($attributes);
        $counts['created']++;

        return $store;
    }

    /**
     * @param  array<string, LaboratoryTest>  $catalogTests
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     * @return \Illuminate\Support\Collection<int, LaboratoryCartItem>
     */
    private function upsertCartItems(Customer $customer, array $catalogTests, array &$ids, array &$counts)
    {
        $manifestIds = collect((array) Arr::get($ids, 'cart_items', []))->filter()->values();
        $definitions = [
            [
                'customer_id' => $customer->id,
                'laboratory_test_id' => $catalogTests['olab']->id,
            ],
            [
                'customer_id' => $customer->id,
                'laboratory_test_id' => $catalogTests['swisslab']->id,
            ],
        ];

        $items = collect();

        foreach ($definitions as $index => $attributes) {
            $item = $manifestIds->has($index)
                ? LaboratoryCartItem::query()->withTrashed()->find((int) $manifestIds[$index])
                : LaboratoryCartItem::query()->withTrashed()
                    ->where('customer_id', $attributes['customer_id'])
                    ->where('laboratory_test_id', $attributes['laboratory_test_id'])
                    ->first();

            if ($item !== null) {
                if ($item->trashed()) {
                    $item->restore();
                }
                $item->fill($attributes);
                $item->save();
                $counts['updated']++;
                $items->push($item->fresh());
                continue;
            }

            $items->push(LaboratoryCartItem::query()->create($attributes));
            $counts['created']++;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     */
    private function upsertCheckoutDraft(Customer $customer, Contact $contact, Address $address, array &$ids, array &$counts): LaboratoryCheckoutDraft
    {
        $draftId = (int) Arr::get($ids, 'checkout_draft', 0);

        $attributes = [
            'customer_id' => $customer->id,
            'laboratory_brand' => LaboratoryBrand::OLAB->value,
            'contact_id' => $contact->id,
            'address_id' => $address->id,
            'payment_method' => 'cash',
            'checkout_step' => 'confirmation',
        ];

        if ($draftId > 0 && ($draft = LaboratoryCheckoutDraft::query()->find($draftId))) {
            $draft->fill($attributes);
            $draft->save();
            $counts['updated']++;

            return $draft->fresh();
        }

        $draft = LaboratoryCheckoutDraft::query()->updateOrCreate(
            [
                'customer_id' => $customer->id,
                'laboratory_brand' => LaboratoryBrand::OLAB->value,
            ],
            $attributes,
        );

        $counts[$draft->wasRecentlyCreated ? 'created' : 'updated']++;

        return $draft;
    }

    /**
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     * @return array<string, Coupon>
     */
    private function upsertCoupons(string $namespace, array &$ids, array &$counts): array
    {
        $naturalKeys = $this->naturalKeys($namespace);
        $definitions = [
            'valid' => [
                'code' => $naturalKeys['coupon_codes']['valid'],
                'description' => '[UAT] saldo valido',
                'amount_cents' => 50000,
                'remaining_cents' => 50000,
                'valid_from' => now()->subDay(),
                'expires_at' => now()->addDays(7),
                'min_purchase_cents' => 10000,
                'type' => CouponType::Balance,
                'is_active' => true,
                'approval_status' => CouponApprovalStatus::Active,
            ],
            'used' => [
                'code' => $naturalKeys['coupon_codes']['used'],
                'description' => '[UAT] saldo usado',
                'amount_cents' => 30000,
                'remaining_cents' => 0,
                'valid_from' => now()->subDays(5),
                'expires_at' => now()->subDay(),
                'min_purchase_cents' => 10000,
                'type' => CouponType::Balance,
                'is_active' => false,
                'approval_status' => CouponApprovalStatus::Active,
            ],
            'not_applicable' => [
                'code' => $naturalKeys['coupon_codes']['not_applicable'],
                'description' => '[UAT] saldo no aplicable',
                'amount_cents' => 10000,
                'remaining_cents' => 10000,
                'valid_from' => now()->subDay(),
                'expires_at' => now()->addDays(7),
                'min_purchase_cents' => 90000,
                'type' => CouponType::Balance,
                'is_active' => true,
                'approval_status' => CouponApprovalStatus::Active,
            ],
        ];

        $coupons = [];
        foreach ($definitions as $slot => $attributes) {
            $couponId = (int) Arr::get($ids, "coupons.{$slot}", 0);
            if ($couponId > 0 && ($coupon = Coupon::query()->find($couponId))) {
                $coupon->fill($attributes);
                $coupon->save();
                $counts['updated']++;
                $coupons[$slot] = $coupon->fresh();
                continue;
            }

            $coupon = Coupon::query()->create($attributes);
            $counts['created']++;
            $coupons[$slot] = $coupon;
        }

        return $coupons;
    }

    /**
     * @param  array<string, Coupon>  $coupons
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     * @return array<string, CouponUser>
     */
    private function upsertCouponAssignments(User $user, array $coupons, array &$ids, array &$counts): array
    {
        $assignments = [];

        foreach (['valid', 'used'] as $slot) {
            $assignmentId = (int) Arr::get($ids, "coupon_assignments.{$slot}", 0);

            if ($assignmentId > 0 && ($assignment = CouponUser::query()->find($assignmentId))) {
                $assignment->fill([
                    'coupon_id' => $coupons[$slot]->id,
                    'user_id' => $user->id,
                    'assigned_at' => now()->subDay(),
                    'used_at' => $slot === 'used' ? now()->subHours(6) : null,
                ]);
                $assignment->save();
                $counts['updated']++;
                $assignments[$slot] = $assignment->fresh();
                continue;
            }

            $assignment = CouponUser::query()->create([
                'coupon_id' => $coupons[$slot]->id,
                'user_id' => $user->id,
                'assigned_at' => now()->subDay(),
                'used_at' => $slot === 'used' ? now()->subHours(6) : null,
            ]);
            $counts['created']++;
            $assignments[$slot] = $assignment;
        }

        return $assignments;
    }

    /**
     * @param  array<string, LaboratoryTest>  $catalogTests
     * @param  array<string, Coupon>  $coupons
     * @param  array<string, mixed>  $ids
     * @param  array<string, int>  $counts
     * @param  array<int, string>  $storagePaths
     * @return array<string, LaboratoryPurchase>
     */
    private function upsertPurchases(
        string $namespace,
        Customer $primaryCustomer,
        Customer $foreignCustomer,
        TaxProfile $primaryTaxProfile,
        TaxProfile $foreignTaxProfile,
        array $catalogTests,
        array $coupons,
        array &$ids,
        array &$counts,
        array $storagePaths,
    ): array {
        $paths = collect($storagePaths)->keyBy(fn (string $path): string => Str::afterLast($path, '/'));

        $definitions = [
            'results_ready' => [
                'customer' => $primaryCustomer,
                'gda_order_id' => strtoupper($namespace).'-RESULT-READY',
                'results' => $paths['result-ready.pdf'],
                'ready_at' => now()->subMinutes(30),
                'coupon_discount_cents' => 0,
                'invoice' => null,
                'invoice_request' => null,
            ],
            'results_pending' => [
                'customer' => $primaryCustomer,
                'gda_order_id' => strtoupper($namespace).'-RESULT-PENDING',
                'results' => null,
                'ready_at' => null,
                'coupon_discount_cents' => 0,
                'invoice' => null,
                'invoice_request' => null,
            ],
            'invoice_ready' => [
                'customer' => $primaryCustomer,
                'gda_order_id' => strtoupper($namespace).'-INVOICE-READY',
                'results' => null,
                'ready_at' => null,
                'coupon_discount_cents' => 5000,
                'invoice' => [
                    'invoice' => $paths['invoice-ready.pdf'],
                    'invoice_xml' => $paths['invoice-ready.xml'],
                    'completed_at' => now()->subMinutes(15),
                ],
                'invoice_request' => null,
            ],
            'invoice_request_pending' => [
                'customer' => $primaryCustomer,
                'gda_order_id' => strtoupper($namespace).'-INVOICE-REQUEST',
                'results' => null,
                'ready_at' => null,
                'coupon_discount_cents' => 0,
                'invoice' => null,
                'invoice_request' => [
                    'tax_profile_id' => $primaryTaxProfile->id,
                    'name' => '[UAT] Razon Fiscal',
                    'rfc' => 'XAXX010101000',
                    'zipcode' => '64000',
                    'tax_regime' => '601',
                    'cfdi_use' => 'G03',
                    'fiscal_certificate' => $paths['fiscal-certificate.pdf'],
                ],
            ],
            'foreign_order' => [
                'customer' => $foreignCustomer,
                'gda_order_id' => strtoupper($namespace).'-FOREIGN-ORDER',
                'results' => $paths['foreign-order.pdf'],
                'ready_at' => now()->subMinutes(20),
                'coupon_discount_cents' => 0,
                'invoice' => [
                    'invoice' => $paths['foreign-order.pdf'],
                    'invoice_xml' => $paths['foreign-order.xml'],
                    'completed_at' => now()->subMinutes(10),
                ],
                'invoice_request' => [
                    'tax_profile_id' => $foreignTaxProfile->id,
                    'name' => '[UAT] Razon Fiscal',
                    'rfc' => 'XEXX010101000',
                    'zipcode' => '64000',
                    'tax_regime' => '601',
                    'cfdi_use' => 'G03',
                    'fiscal_certificate' => $paths['fiscal-certificate.pdf'],
                ],
            ],
        ];

        $purchases = [];

        foreach ($definitions as $slot => $definition) {
            $purchaseId = (int) Arr::get($ids, "purchases.{$slot}", 0);
            $customer = $definition['customer'];
            $attributes = [
                'customer_id' => $customer->id,
                'brand' => LaboratoryBrand::OLAB->value,
                'gda_order_id' => $definition['gda_order_id'],
                'gda_consecutivo' => crc32($definition['gda_order_id']) % 1000000,
                'name' => '[UAT]',
                'paternal_lastname' => 'FAMEDIC',
                'maternal_lastname' => 'AKUBICA',
                'phone' => $customer->user->phone,
                'phone_country' => $customer->user->phone_country,
                'birth_date' => '1990-01-01',
                'gender' => Gender::MALE->value,
                'street' => '[UAT] Avenida FAMEDIC',
                'number' => '100',
                'neighborhood' => 'AKUBICA',
                'state' => 'Nuevo Leon',
                'city' => 'Monterrey',
                'zipcode' => '64000',
                'total_cents' => 35000,
                'status' => 'completed',
                'results' => $definition['results'],
                'ready_at' => $definition['ready_at'],
                'completed_at' => now()->subMinutes(5),
                'coupon_discount_cents' => $definition['coupon_discount_cents'],
            ];

            if ($purchaseId > 0 && ($purchase = LaboratoryPurchase::query()->withTrashed()->find($purchaseId))) {
                if ($purchase->trashed()) {
                    $purchase->restore();
                }
                $purchase->fill($attributes);
                $purchase->save();
                $counts['updated']++;
            } else {
                $purchase = LaboratoryPurchase::query()->create($attributes);
                $counts['created']++;
            }

            $this->reconcilePurchaseItems($purchase, $catalogTests[$slot === 'results_pending' ? 'swisslab' : 'olab'], (array) Arr::get($ids, "purchase_items.{$slot}", []), $counts);
            $this->reconcilePurchaseRelations($purchase, $definition, (array) Arr::get($ids, "invoices", []), (array) Arr::get($ids, "invoice_requests", []), $slot, $counts);
            $purchases[$slot] = $purchase->fresh(['laboratoryPurchaseItems', 'invoice', 'invoiceRequest']);
        }

        return $purchases;
    }

    /**
     * @param  array<string, int>  $counts
     */
    private function reconcilePurchaseItems(LaboratoryPurchase $purchase, LaboratoryTest $test, array $itemIds, array &$counts): void
    {
        $attributes = [
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => $test->gda_id,
            'name' => $test->name,
            'indications' => $test->indications ?: '[UAT] indicaciones sinteticas',
            'price_cents' => $test->famedic_price_cents,
            'feature_list' => $test->feature_list,
        ];

        $item = ! empty($itemIds)
            ? LaboratoryPurchaseItem::query()->withTrashed()->find((int) $itemIds[0])
            : LaboratoryPurchaseItem::query()->withTrashed()
                ->where('laboratory_purchase_id', $purchase->id)
                ->where('gda_id', $test->gda_id)
                ->first();

        if ($item !== null) {
            if ($item->trashed()) {
                $item->restore();
            }
            $item->fill($attributes);
            $item->save();
            $counts['updated']++;

            return;
        }

        LaboratoryPurchaseItem::query()->create($attributes);
        $counts['created']++;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, int>  $counts
     */
    private function reconcilePurchaseRelations(LaboratoryPurchase $purchase, array $definition, array $invoiceIds, array $invoiceRequestIds, string $slot, array &$counts): void
    {
        if ($definition['invoice'] !== null) {
            $invoice = isset($invoiceIds[$slot])
                ? Invoice::query()->withTrashed()->find((int) $invoiceIds[$slot])
                : $purchase->invoice()->withTrashed()->first();

            $attributes = [
                'invoiceable_type' => LaboratoryPurchase::class,
                'invoiceable_id' => $purchase->id,
                'invoice' => $definition['invoice']['invoice'],
                'invoice_xml' => $definition['invoice']['invoice_xml'],
                'completed_at' => $definition['invoice']['completed_at'],
            ];

            if ($invoice !== null) {
                if ($invoice->trashed()) {
                    $invoice->restore();
                }
                $invoice->fill($attributes);
                $invoice->save();
                $counts['updated']++;
            } else {
                Invoice::query()->create($attributes);
                $counts['created']++;
            }
        }

        if ($definition['invoice_request'] !== null) {
            $payload = array_merge([
                'invoice_requestable_type' => LaboratoryPurchase::class,
                'invoice_requestable_id' => $purchase->id,
            ], $definition['invoice_request']);

            if (! Schema::hasColumn('invoice_requests', 'tax_profile_id')) {
                unset($payload['tax_profile_id']);
            }

            $invoiceRequest = isset($invoiceRequestIds[$slot])
                ? InvoiceRequest::query()->withTrashed()->find((int) $invoiceRequestIds[$slot])
                : $purchase->invoiceRequest()->withTrashed()->first();

            if ($invoiceRequest !== null) {
                if ($invoiceRequest->trashed()) {
                    $invoiceRequest->restore();
                }
                $invoiceRequest->fill($payload);
                $invoiceRequest->save();
                $counts['updated']++;
            } else {
                InvoiceRequest::query()->create($payload);
                $counts['created']++;
            }
        }
    }

    private function assertManifestIsResettable(AkubicaUatFixtureManifest $manifest, AkubicaUatFixturePlan $plan): void
    {
        if (
            $manifest->namespace !== AkubicaUatFixtureContract::NAMESPACE
            || (int) $manifest->fixture_version !== AkubicaUatFixtureContract::FIXTURE_VERSION
            || ! in_array($manifest->status, AkubicaUatFixtureContract::ALLOWED_STATUSES, true)
            || ! is_array($manifest->metadata)
        ) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        $ids = (array) Arr::get($manifest->metadata, 'ids', []);
        $naturalKeys = $this->naturalKeys($plan->namespace);

        foreach (['users', 'regular_accounts', 'customers', 'contacts', 'addresses', 'tax_profiles', 'tests', 'coupons', 'coupon_assignments', 'purchases', 'purchase_items', 'invoices', 'invoice_requests'] as $key) {
            if (! is_array(Arr::get($ids, $key))) {
                throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
            }
        }

        foreach (['invoice_request_pending', 'foreign_order_invoice_request'] as $slot) {
            if (! is_numeric(Arr::get($ids, "invoice_requests.{$slot}"))) {
                throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
            }
        }

        if ((array) Arr::get($manifest->metadata, 'storage_hashes', []) !== $plan->storageHashes) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        if ((array) Arr::get($manifest->metadata, 'natural_key_hashes', []) !== $this->naturalKeyHashes($plan->namespace)) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        $this->assertSyntheticUser('primary', $ids);
        $this->assertSyntheticUser('foreign', $ids);
        $this->assertSyntheticCustomer('primary', $ids);
        $this->assertSyntheticCustomer('foreign', $ids);
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.primary'), Contact::class, (int) Arr::get($ids, 'contacts.primary'), 'customer_id');
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.foreign'), Contact::class, (int) Arr::get($ids, 'contacts.foreign'), 'customer_id');
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.primary'), Address::class, (int) Arr::get($ids, 'addresses.primary'), 'customer_id');
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.foreign'), Address::class, (int) Arr::get($ids, 'addresses.foreign'), 'customer_id');
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.primary'), TaxProfile::class, (int) Arr::get($ids, 'tax_profiles.primary'), 'customer_id');
        $this->assertChild(Customer::class, (int) Arr::get($ids, 'customers.foreign'), TaxProfile::class, (int) Arr::get($ids, 'tax_profiles.foreign'), 'customer_id');

        $category = LaboratoryTestCategory::query()->withTrashed()->find((int) Arr::get($ids, 'category'));
        if ($category === null || $category->name !== $naturalKeys['category_name']) {
            throw new AkubicaUatFixtureException('UAT_RESET_CATEGORY_MISMATCH');
        }

        foreach ($naturalKeys['test_gda_ids'] as $slot => $gdaId) {
            $test = LaboratoryTest::query()->withTrashed()->find((int) Arr::get($ids, "tests.{$slot}"));
            if ($test === null || $test->gda_id !== $gdaId || (int) $test->laboratory_test_category_id !== (int) $category->id) {
                throw new AkubicaUatFixtureException('UAT_RESET_TEST_MISMATCH');
            }
        }

        $store = LaboratoryStore::query()->withTrashed()->find((int) Arr::get($ids, 'store'));
        if ($store === null || $store->name !== $naturalKeys['store_name']) {
            throw new AkubicaUatFixtureException('UAT_RESET_STORE_MISMATCH');
        }

        foreach ($naturalKeys['coupon_codes'] as $slot => $code) {
            $coupon = Coupon::query()->find((int) Arr::get($ids, "coupons.{$slot}"));
            if ($coupon === null || $coupon->code !== $code) {
                throw new AkubicaUatFixtureException('UAT_RESET_COUPON_MISMATCH');
            }
        }

        foreach ((array) Arr::get($ids, 'coupon_assignments', []) as $assignmentId) {
            $assignment = CouponUser::query()->find((int) $assignmentId);
            if (
                $assignment === null
                || ! in_array((int) $assignment->coupon_id, array_map('intval', array_values((array) Arr::get($ids, 'coupons', []))), true)
                || (int) $assignment->user_id !== (int) Arr::get($ids, 'users.primary')
            ) {
                throw new AkubicaUatFixtureException('UAT_RESET_COUPON_ASSIGNMENT_MISMATCH');
            }
        }

        foreach ((array) Arr::get($ids, 'cart_items', []) as $cartItemId) {
            $cartItem = LaboratoryCartItem::query()->withTrashed()->find((int) $cartItemId);
            if (
                $cartItem === null
                || (int) $cartItem->customer_id !== (int) Arr::get($ids, 'customers.primary')
                || ! in_array((int) $cartItem->laboratory_test_id, array_map('intval', array_values((array) Arr::get($ids, 'tests', []))), true)
            ) {
                throw new AkubicaUatFixtureException('UAT_RESET_CART_ITEM_MISMATCH');
            }
        }

        foreach ((array) Arr::get($ids, 'purchases', []) as $slot => $purchaseId) {
            $purchase = LaboratoryPurchase::query()->withTrashed()->find((int) $purchaseId);
            $expectedGda = match ((string) $slot) {
                'results_ready' => strtoupper($plan->namespace).'-RESULT-READY',
                'results_pending' => strtoupper($plan->namespace).'-RESULT-PENDING',
                'invoice_ready' => strtoupper($plan->namespace).'-INVOICE-READY',
                'invoice_request_pending' => strtoupper($plan->namespace).'-INVOICE-REQUEST',
                'foreign_order' => strtoupper($plan->namespace).'-FOREIGN-ORDER',
                default => null,
            };

            if ($expectedGda === null || $purchase === null || $purchase->gda_order_id !== $expectedGda) {
                throw new AkubicaUatFixtureException('UAT_RESET_PURCHASE_MISMATCH');
            }

            foreach ((array) Arr::get($ids, "purchase_items.{$slot}", []) as $itemId) {
                $item = LaboratoryPurchaseItem::query()->withTrashed()->find((int) $itemId);
                if ($item === null || (int) $item->laboratory_purchase_id !== (int) $purchase->id || blank($item->indications)) {
                    throw new AkubicaUatFixtureException('UAT_RESET_PURCHASE_ITEM_MISMATCH');
                }
            }
        }

        foreach ((array) Arr::get($ids, 'invoices', []) as $slot => $invoiceId) {
            if ($invoiceId === null) {
                continue;
            }
            $invoice = Invoice::query()->withTrashed()->find((int) $invoiceId);
            $purchaseId = (int) Arr::get($ids, "purchases.{$slot}");
            if ($invoice === null || $invoice->invoiceable_type !== LaboratoryPurchase::class || (int) $invoice->invoiceable_id !== $purchaseId) {
                throw new AkubicaUatFixtureException('UAT_RESET_INVOICE_MISMATCH');
            }
        }

        foreach ((array) Arr::get($ids, 'invoice_requests', []) as $slot => $invoiceRequestId) {
            if ($invoiceRequestId === null) {
                continue;
            }
            $invoiceRequest = InvoiceRequest::query()->withTrashed()->find((int) $invoiceRequestId);
            $purchaseSlot = $slot === 'foreign_order_invoice_request' ? 'foreign_order' : $slot;
            $purchaseId = (int) Arr::get($ids, "purchases.{$purchaseSlot}");
            $expectedHash = Arr::get($manifest->metadata, "natural_key_hashes.invoice_requests.{$slot}");

            if (
                $invoiceRequest === null
                || $invoiceRequest->invoice_requestable_type !== LaboratoryPurchase::class
                || (int) $invoiceRequest->invoice_requestable_id !== $purchaseId
                || $expectedHash !== Arr::get($this->naturalKeyHashes($plan->namespace), "invoice_requests.{$slot}")
            ) {
                throw new AkubicaUatFixtureException('UAT_RESET_INVOICE_REQUEST_MISMATCH');
            }
        }

        $this->assertNoExternalReferences($ids);
        $this->assertResettableStorage($plan);
    }

    private function assertSyntheticUser(string $role, array $ids): void
    {
        $identity = $this->requiredIdentities()[$role];
        $user = User::query()->find((int) Arr::get($ids, "users.{$role}"));
        $rawPhone = $user?->getRawOriginal('phone');

        if ($user === null || $user->email !== $identity['email'] || $rawPhone !== $identity['phone'] || $user->phone_country !== $identity['country']) {
            throw new AkubicaUatFixtureException('UAT_RESET_USER_MISMATCH');
        }
    }

    private function assertSyntheticCustomer(string $role, array $ids): void
    {
        $customer = Customer::query()->withTrashed()->find((int) Arr::get($ids, "customers.{$role}"));

        if (
            $customer === null
            || (int) $customer->user_id !== (int) Arr::get($ids, "users.{$role}")
            || $customer->medical_attention_identifier !== $this->naturalKeys(AkubicaUatFixtureContract::NAMESPACE)['customer_identifiers'][$role]
            || $customer->customerable_type !== RegularAccount::class
            || (int) $customer->customerable_id !== (int) Arr::get($ids, "regular_accounts.{$role}")
        ) {
            throw new AkubicaUatFixtureException('UAT_RESET_CUSTOMER_MISMATCH');
        }
    }

    private function assertChild(string $parentClass, int $parentId, string $childClass, int $childId, string $foreignKey): void
    {
        if ($parentId < 1 || $childId < 1 || $parentClass::query()->withTrashed()->find($parentId) === null) {
            throw new AkubicaUatFixtureException('UAT_MANIFEST_CORRUPT');
        }

        $child = $childClass::query()->withTrashed()->find($childId);
        if ($child === null || (int) $child->{$foreignKey} !== $parentId) {
            throw new AkubicaUatFixtureException('UAT_RESET_PARENT_MISMATCH');
        }
    }

    private function assertNoExternalReferences(array $ids): void
    {
        $couponIds = array_map('intval', array_values((array) Arr::get($ids, 'coupons', [])));
        $purchaseIds = array_map('intval', array_values((array) Arr::get($ids, 'purchases', [])));
        $testIds = array_map('intval', array_values((array) Arr::get($ids, 'tests', [])));

        if (CouponUser::query()->whereIn('coupon_id', $couponIds)->whereNotIn('id', array_map('intval', array_values((array) Arr::get($ids, 'coupon_assignments', []))))->exists()) {
            throw new AkubicaUatFixtureException('UAT_RESET_EXTERNAL_REFERENCE');
        }

        if (LaboratoryPurchaseItem::query()->withTrashed()->whereIn('laboratory_purchase_id', $purchaseIds)->whereNotIn('id', collect((array) Arr::get($ids, 'purchase_items', []))->flatten()->map(fn ($id) => (int) $id)->all())->exists()) {
            throw new AkubicaUatFixtureException('UAT_RESET_EXTERNAL_REFERENCE');
        }

        if (LaboratoryCartItem::query()->withTrashed()->whereIn('laboratory_test_id', $testIds)->whereNotIn('id', array_map('intval', (array) Arr::get($ids, 'cart_items', [])))->exists()) {
            throw new AkubicaUatFixtureException('UAT_RESET_EXTERNAL_REFERENCE');
        }
    }

    private function assertResettableStorage(AkubicaUatFixturePlan $plan): void
    {
        $disk = Storage::disk(AkubicaUatFixtureContract::STORAGE_DISK);

        foreach ($plan->storageHashes as $path => $hash) {
            $this->assertAllowedStoragePath($path);

            if (! $disk->exists($path)) {
                throw new AkubicaUatFixtureException('UAT_RESET_STORAGE_MISSING');
            }

            if (hash('sha256', (string) $disk->get($path)) !== $hash) {
                throw new AkubicaUatFixtureException('UAT_RESET_STORAGE_HASH_MISMATCH');
            }
        }
    }

    /**
     * @return array{status: string, record_ids: list<int>, actor_hashes: list<string>, operations: list<array{method: string, path: string}>, window_started_at: CarbonImmutable|null, window_ended_at: CarbonImmutable|null}
     */
    private function assertResettableIdempotencyRecords(AkubicaUatFixtureManifest $manifest): array
    {
        $metadata = (array) $manifest->metadata;
        $recordIds = $this->idempotencyRecordIdsFromMetadata((array) Arr::get($metadata, 'idempotency_record_ids', []));

        if ($recordIds === []) {
            return [
                'status' => 'not_recorded',
                'record_ids' => [],
                'actor_hashes' => [],
                'operations' => [],
                'window_started_at' => null,
                'window_ended_at' => null,
            ];
        }

        $actorHashes = collect((array) Arr::get($metadata, 'idempotency_actor_hashes', []))
            ->filter(fn ($hash) => is_string($hash) && $hash !== '' && $hash !== 'not_configured')
            ->values()
            ->all();

        $operations = $this->idempotencyOperationsFromMetadata((array) Arr::get($metadata, 'idempotency_allowed_operations', []));
        $windowStartedAt = $this->idempotencyWindowBoundary(Arr::get($metadata, 'idempotency_window_started_at'));
        $windowEndedAt = $this->idempotencyWindowBoundary(Arr::get($metadata, 'idempotency_window_ended_at'));

        if (
            $actorHashes === []
            || $operations !== self::IDEMPOTENCY_ALLOWED_OPERATIONS
            || $windowStartedAt === null
            || $windowEndedAt === null
            || $windowStartedAt->greaterThan($windowEndedAt)
        ) {
            throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
        }

        $records = IdempotencyRecord::query()
            ->whereIn('id', $recordIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($records->count() !== count($recordIds)) {
            throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
        }

        foreach ($recordIds as $recordId) {
            $record = $records->get($recordId);
            $createdAt = $record?->created_at instanceof CarbonImmutable
                ? $record->created_at
                : ($record?->created_at !== null ? CarbonImmutable::parse($record->created_at) : null);

            if (
                $record === null
                || ! in_array($record->actor_key, $actorHashes, true)
                || ! $this->idempotencyOperationIsAllowed((string) $record->method, (string) $record->path, $operations)
                || $createdAt === null
                || $createdAt->lessThan($windowStartedAt)
                || $createdAt->greaterThan($windowEndedAt)
            ) {
                throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
            }
        }

        return [
            'status' => 'verified_ids',
            'record_ids' => $recordIds,
            'actor_hashes' => $actorHashes,
            'operations' => $operations,
            'window_started_at' => $windowStartedAt,
            'window_ended_at' => $windowEndedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $cleanup
     * @param  array<string, int>  $counts
     */
    private function deleteVerifiedIdempotencyRecords(array $cleanup, array &$counts): void
    {
        $recordIds = (array) ($cleanup['record_ids'] ?? []);

        if ($recordIds === []) {
            return;
        }

        $actorHashes = (array) ($cleanup['actor_hashes'] ?? []);
        $operations = (array) ($cleanup['operations'] ?? []);
        $windowStartedAt = $cleanup['window_started_at'] ?? null;
        $windowEndedAt = $cleanup['window_ended_at'] ?? null;

        if (! $windowStartedAt instanceof CarbonImmutable || ! $windowEndedAt instanceof CarbonImmutable) {
            throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
        }

        $this->deleteVerifiedRecords(IdempotencyRecord::query(), [], $counts, function ($query) use ($recordIds, $actorHashes, $operations, $windowStartedAt, $windowEndedAt): void {
            $query
                ->whereIn('id', $recordIds)
                ->whereIn('actor_key', $actorHashes)
                ->whereBetween('created_at', [$windowStartedAt, $windowEndedAt])
                ->where(function ($operationQuery) use ($operations): void {
                    foreach ($operations as $index => $operation) {
                        $operationQuery->{$index === 0 ? 'where' : 'orWhere'}(function ($pairQuery) use ($operation): void {
                            $pairQuery
                                ->where('method', $operation['method'])
                                ->where('path', $operation['path']);
                        });
                    }
                });
        });
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private function idempotencyRecordIdsFromMetadata(array $ids): array
    {
        $recordIds = [];

        foreach ($ids as $id) {
            if (! is_int($id) && ! (is_string($id) && ctype_digit($id))) {
                throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
            }

            $recordIds[] = (int) $id;
        }

        $uniqueIds = array_values(array_unique($recordIds));

        if ($recordIds !== $uniqueIds || in_array(0, $recordIds, true)) {
            throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
        }

        return $recordIds;
    }

    /**
     * @param  array<int, mixed>  $operations
     * @return list<array{method: string, path: string}>
     */
    private function idempotencyOperationsFromMetadata(array $operations): array
    {
        $normalized = [];

        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['method'] ?? null) || ! is_string($operation['path'] ?? null)) {
                throw new AkubicaUatFixtureException('UAT_RESET_IDEMPOTENCY_MISMATCH');
            }

            $normalized[] = [
                'method' => strtoupper($operation['method']),
                'path' => ltrim($operation['path'], '/'),
            ];
        }

        return $normalized;
    }

    private function idempotencyWindowBoundary(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{method: string, path: string}>  $operations
     */
    private function idempotencyOperationIsAllowed(string $method, string $path, array $operations): bool
    {
        $method = strtoupper($method);
        $path = ltrim($path, '/');

        foreach ($operations as $operation) {
            if ($operation['method'] === $method && $operation['path'] === $path) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @param  array<int, int|string>  $ids
     * @param  array<string, int>  $counts
     * @param  null|callable(\Illuminate\Database\Eloquent\Builder): void  $scope
     */
    private function deleteVerifiedRecords($query, array $ids, array &$counts, ?callable $scope = null): void
    {
        $builder = clone $query;

        if ($scope !== null) {
            $scope($builder);
        } elseif ($ids !== []) {
            $builder->whereIn('id', $ids);
        } else {
            return;
        }

        $records = $builder->get();
        foreach ($records as $record) {
            if (in_array('Illuminate\Database\Eloquent\SoftDeletes', class_uses_recursive($record), true)) {
                $record->forceDelete();
            } else {
                $record->delete();
            }
            $counts['deleted']++;
        }
    }

    /**
     * @param  array<string, mixed>  $ids
     * @return array<int, int>
     */
    private function userIdsFromMetadata(array $ids): array
    {
        return collect((array) Arr::get($ids, 'users', []))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
