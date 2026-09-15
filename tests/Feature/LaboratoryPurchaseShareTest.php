<?php

namespace Tests\Feature;

use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\Invoice;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryAppointment;
use App\Models\LaboratoryConcierge;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchaseShare;
use App\Models\LaboratoryStore;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LaboratoryPurchaseShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_create_share_with_plain_url_and_hashed_token_storage(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $response = $this->actingAs($owner)->postJson(route('laboratory-purchases.shares.store', $purchase));

        $response->assertCreated()
            ->assertJsonPath('share.id', 1)
            ->assertJsonStructure(['share' => ['id', 'expires_at', 'formatted_expires_at'], 'url']);

        $url = (string) $response->json('url');
        $share = LaboratoryPurchaseShare::query()->firstOrFail();

        $this->assertStringContainsString('/shared/laboratory-orders/', $url);
        $this->assertDoesNotMatchRegularExpression('/'.$share->token_hash.'/', $url);
        $this->assertSame(64, strlen($share->token_hash));
        $this->assertNotNull($share->expires_at);
        $this->assertNull($share->revoked_at);
    }

    public function test_guest_and_non_owner_cannot_create_share(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $otherUser = $this->createCustomerUser();

        $this->postJson(route('laboratory-purchases.shares.store', $purchase))->assertUnauthorized();
        $this->actingAs($otherUser)->postJson(route('laboratory-purchases.shares.store', $purchase))->assertForbidden();

        $this->assertDatabaseCount('laboratory_purchase_shares', 0);
    }

    public function test_admin_or_billing_view_permission_does_not_grant_share_creation(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $admin = User::factory()->withCompleteProfile()->withAdministrator()->create([
            'documentation_accepted_at' => now(),
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => LaboratoryBillingAccess::PERMISSION_INVOICES, 'guard_name' => 'web']);
        $role = Role::firstOrCreate(['name' => 'billing-fixture', 'guard_name' => 'web']);
        $role->givePermissionTo(LaboratoryBillingAccess::PERMISSION_INVOICES);
        $admin->administrator->assignRole($role);

        $this->assertTrue($admin->can('view', $purchase));
        $this->assertFalse($admin->can('createShare', $purchase));
        $this->assertTrue($owner->can('createShare', $purchase));
    }

    public function test_non_owner_admin_billing_and_concierge_cannot_create_share_over_http(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $admin = $this->createAdministratorUser();
        $billing = $this->createAdministratorUser();
        $concierge = $this->createAdministratorUser();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::firstOrCreate(['name' => 'laboratory-purchases.manage', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => LaboratoryBillingAccess::PERMISSION_INVOICES, 'guard_name' => 'web']);

        $admin->administrator->givePermissionTo('laboratory-purchases.manage');
        $billing->administrator->givePermissionTo(LaboratoryBillingAccess::PERMISSION_INVOICES);
        LaboratoryConcierge::query()->create(['administrator_id' => $concierge->administrator->id]);

        $this->actingAs($admin->fresh('administrator'))
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->assertForbidden();

        $this->actingAs($billing->fresh('administrator'))
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->assertForbidden();

        $this->actingAs($concierge->fresh('administrator.laboratoryConcierge'))
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->assertForbidden();

        $this->assertTrue($owner->can('createShare', $purchase));
        $this->assertDatabaseCount('laboratory_purchase_shares', 0);
    }

    public function test_creating_second_share_revokes_first_token(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $firstUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $firstShare = LaboratoryPurchaseShare::query()->firstOrFail();

        $secondUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertNotNull($firstShare->refresh()->revoked_at);
        $this->assertSame(1, LaboratoryPurchaseShare::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereNull('revoked_at')
            ->count());

        $this->get($firstUrl)->assertNotFound();
        $this->get($secondUrl)->assertOk();
    }

    public function test_share_regeneration_leaves_only_latest_share_active(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $firstUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $firstShare = LaboratoryPurchaseShare::query()->firstOrFail();

        $secondUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $secondShare = LaboratoryPurchaseShare::query()->latest('id')->firstOrFail();

        $this->assertNotSame($firstShare->id, $secondShare->id);
        $this->assertNotNull($firstShare->refresh()->revoked_at);
        $this->assertNull($secondShare->refresh()->revoked_at);
        $this->assertSame(1, LaboratoryPurchaseShare::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereNull('revoked_at')
            ->count());

        $this->get($firstUrl)->assertNotFound();
        $this->get($secondUrl)->assertOk();
    }

    public function test_public_share_renders_allowlisted_order_data_only(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture(results: 'private-results-file.pdf');
        $this->attachSensitiveRelatedRecords($purchase);

        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $response = $this->get($url);

        $response->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertSee('Este enlace permite consultar', false)
            ->assertSee('Paciente Share', false)
            ->assertSee('Biometria hematica', false)
            ->assertSee('Ayuno 8 horas', false)
            ->assertSee('Sucursal Share', false)
            ->assertDontSee('private-results-file.pdf', false)
            ->assertDontSee('private-invoice.pdf', false)
            ->assertDontSee('private-invoice.xml', false)
            ->assertDontSee('XAXX010101000', false)
            ->assertDontSee('private-certificate.pdf', false)
            ->assertDontSee('secret-gateway-token', false)
            ->assertDontSee('8111111111', false)
            ->assertDontSee('1991-05-14', false)
            ->assertDontSee('total_cents', false)
            ->assertDontSee('transactions', false)
            ->assertDontSee('invoiceRequest', false)
            ->assertDontSee('invoice_requestable', false);
    }

    public function test_public_share_root_view_does_not_render_global_tracking_scripts(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $response = $this->get($url);

        $response->assertOk()
            ->assertDontSee('GTM-T39QLNX6', false)
            ->assertDontSee('G-F5VNYJNMBP', false)
            ->assertDontSee('1818127705804364', false)
            ->assertDontSee('6467565', false)
            ->assertDontSee('69689492', false)
            ->assertDontSee('googletagmanager.com', false)
            ->assertDontSee('connect.facebook.net', false)
            ->assertDontSee('static.hotjar.com', false)
            ->assertDontSee('diffuser-cdn.app-us1.com', false)
            ->assertDontSee('cdn.usefathom.com', false);
    }

    public function test_public_share_sanitizes_store_google_maps_url(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $store = $purchase->laboratoryAppointment->laboratoryStore;

        foreach ([
            'https://maps.google.com/?q=famedic' => 'https://maps.google.com/?q=famedic',
            'http://maps.example.test/share' => 'http://maps.example.test/share',
            'javascript:alert(1)' => null,
            'data:text/html,<script>alert(1)</script>' => null,
            'mailto:test@example.test' => null,
            '' => null,
        ] as $rawUrl => $expectedUrl) {
            $store->update(['google_maps_url' => $rawUrl]);

            $url = (string) $this->actingAs($owner)
                ->postJson(route('laboratory-purchases.shares.store', $purchase))
                ->json('url');

            $this->get($url)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Shared/LaboratoryOrder')
                    ->where('laboratoryOrder.store.google_maps_url', $expectedUrl));
        }
    }

    public function test_authenticated_public_share_has_no_private_shared_props(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->actingAs($owner)
            ->get($url)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/LaboratoryOrder')
                ->where('auth.user', null)
                ->where('activeCampaignSiteTracking.enabled', false)
                ->where('activeCampaignSiteTracking.email', null)
                ->where('medicalAttentionSubscriptionIsActive', null)
                ->where('formattedMedicalAttentionSubscriptionExpiresAt', null)
                ->where('medicalAttentionIdentifier', null)
                ->where('hasOdessaAfiliateAccount', null)
                ->where('laboratoryCarts', [])
                ->where('onlinePharmacyCart', [])
                ->where('inAppNotificationFeed', null)
                ->where('userNavigation', [])
                ->missing('auth.user.customer')
                ->missing('auth.user.permissions')
                ->missing('auth.user.administrator')
                ->missing('customer')
                ->missing('notifications')
                ->missing('permissions')
                ->missing('administrator'));
    }

    public function test_invalid_expired_revoked_and_cancelled_shared_links_return_404(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $share = LaboratoryPurchaseShare::query()->firstOrFail();

        $this->get('/shared/laboratory-orders/'.str_repeat('a', 64))->assertNotFound();

        $share->update(['expires_at' => now()->subMinute()]);
        $this->get($url)->assertNotFound();

        $share->update(['expires_at' => now()->addDay(), 'revoked_at' => now()]);
        $this->get($url)->assertNotFound();

        $share->update(['revoked_at' => null]);
        $purchase->delete();
        $this->get($url)->assertNotFound();
    }

    public function test_public_view_updates_view_count_and_last_viewed_at(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $share = LaboratoryPurchaseShare::query()->firstOrFail();

        $this->assertSame(0, $share->views_count);
        $this->assertNull($share->last_viewed_at);

        $this->get($url)->assertOk();
        $this->get($url)->assertOk();

        $share->refresh();
        $this->assertSame(2, $share->views_count);
        $this->assertNotNull($share->last_viewed_at);
    }

    /**
     * @return array{0: User, 1: LaboratoryPurchase}
     */
    private function createPurchaseFixture(?string $results = null): array
    {
        $owner = $this->createCustomerUser();

        $purchase = LaboratoryPurchase::query()->create([
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'GDA-SHARE-001',
            'name' => 'Paciente',
            'paternal_lastname' => 'Share',
            'maternal_lastname' => 'Seguro',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1991-05-14',
            'gender' => Gender::FEMALE,
            'street' => 'Calle Privada',
            'number' => '123',
            'neighborhood' => 'Centro',
            'state' => 'Nuevo Leon',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 98765,
            'results' => $results,
            'customer_id' => $owner->customer->id,
        ]);

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'BH-001',
            'name' => 'Biometria hematica',
            'indications' => 'Ayuno 8 horas',
            'feature_list' => [
                ['name' => 'Hemoglobina'],
                ['name' => 'Plaquetas'],
            ],
            'price_cents' => 25000,
        ]);

        $store = LaboratoryStore::query()->create([
            'name' => 'Sucursal Share',
            'brand' => LaboratoryBrand::OLAB,
            'state' => 'Nuevo Leon',
            'address' => 'Av. Prueba 100',
            'weekly_hours' => '7:00 a 15:00',
            'saturday_hours' => '8:00 a 12:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.example.test/share',
            'phone' => '8187654321',
        ]);

        LaboratoryAppointment::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'laboratory_store_id' => $store->id,
            'customer_id' => $owner->customer->id,
            'brand' => LaboratoryBrand::OLAB,
            'appointment_date' => now()->addDay()->setTime(9, 30),
            'confirmed_at' => now(),
            'patient_name' => 'Paciente',
            'patient_paternal_lastname' => 'Share',
            'patient_maternal_lastname' => 'Seguro',
            'patient_phone' => '8122222222',
            'patient_phone_country' => 'MX',
            'patient_birth_date' => '1991-05-14',
            'patient_gender' => Gender::FEMALE,
            'notes' => 'Nota privada de seguimiento',
        ]);

        return [$owner->fresh('customer'), $purchase->refresh()];
    }

    private function createCustomerUser(): User
    {
        return User::factory()->withCompleteProfile()->withRegularCustomer()->create([
            'documentation_accepted_at' => now(),
        ])->fresh('customer');
    }

    private function createAdministratorUser(): User
    {
        return User::factory()->withCompleteProfile()->withAdministrator()->create([
            'documentation_accepted_at' => now(),
        ])->fresh('administrator');
    }

    private function attachSensitiveRelatedRecords(LaboratoryPurchase $purchase): void
    {
        Invoice::query()->create([
            'invoiceable_type' => LaboratoryPurchase::class,
            'invoiceable_id' => $purchase->id,
            'invoice' => 'private-invoice.pdf',
            'invoice_xml' => 'private-invoice.xml',
        ]);

        InvoiceRequest::query()->create([
            'invoice_requestable_type' => LaboratoryPurchase::class,
            'invoice_requestable_id' => $purchase->id,
            'name' => 'Empresa Privada',
            'rfc' => 'XAXX010101000',
            'zipcode' => '64000',
            'tax_regime' => '601',
            'cfdi_use' => 'G03',
            'fiscal_certificate' => 'private-certificate.pdf',
        ]);

        $transaction = Transaction::query()->create([
            'transaction_amount_cents' => 98765,
            'payment_method' => 'stripe',
            'reference_id' => 'private-reference',
            'payment_status' => 'completed',
            'gateway_token' => 'secret-gateway-token',
        ]);

        $purchase->transactions()->attach($transaction);
    }
}
