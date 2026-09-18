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
use App\Models\LaboratoryTest;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Transaction;
use App\Models\User;
use App\Services\LaboratoryBilling\LaboratoryBillingAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    public function test_opening_share_twice_reuses_token_and_renews_expiration(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $this->travelTo(now()->startOfMinute());

        $firstResponse = $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase));
        $firstUrl = (string) $firstResponse->json('url');
        $firstShare = LaboratoryPurchaseShare::query()->firstOrFail();
        $firstExpiresAt = $firstShare->expires_at?->copy();

        $this->assertSame(201, $firstResponse->status());
        $this->assertTrue($firstResponse->json('created'));

        $this->travel(6)->hours();

        $secondResponse = $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase));
        $secondUrl = (string) $secondResponse->json('url');

        $this->assertSame(200, $secondResponse->status());
        $this->assertFalse($secondResponse->json('created'));
        $this->assertSame($firstUrl, $secondUrl);
        $this->assertSame($firstShare->id, $firstShare->refresh()->id);
        $this->assertNull($firstShare->revoked_at);
        $this->assertTrue($firstShare->expires_at->gt($firstExpiresAt));
        $this->assertSame(
            now()->addHours(72)->toDateTimeString(),
            $firstShare->expires_at->toDateTimeString(),
        );
        $this->assertSame(1, LaboratoryPurchaseShare::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereNull('revoked_at')
            ->count());

        $this->get($firstUrl)->assertOk();
    }

    public function test_share_renewal_keeps_only_one_active_share_record(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $firstUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $firstShare = LaboratoryPurchaseShare::query()->firstOrFail();

        $secondUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->assertSame($firstUrl, $secondUrl);
        $this->assertSame($firstShare->id, $firstShare->refresh()->id);
        $this->assertNull($firstShare->revoked_at);
        $this->assertSame(1, LaboratoryPurchaseShare::query()
            ->where('laboratory_purchase_id', $purchase->id)
            ->whereNull('revoked_at')
            ->count());

        $this->get($firstUrl)->assertOk();
    }

    public function test_revoked_share_is_replaced_with_a_new_token_on_next_open(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();

        $firstUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $firstShare = LaboratoryPurchaseShare::query()->firstOrFail();

        $this->actingAs($owner)
            ->deleteJson(route('laboratory-purchases.shares.destroy', [
                'laboratory_purchase' => $purchase,
                'share' => $firstShare,
            ]))
            ->assertOk();

        $secondUrl = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');
        $secondShare = LaboratoryPurchaseShare::query()->latest('id')->firstOrFail();

        $this->assertNotSame($firstUrl, $secondUrl);
        $this->assertNotSame($firstShare->id, $secondShare->id);
        $this->assertNotNull($firstShare->refresh()->revoked_at);
        $this->assertNull($secondShare->revoked_at);

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
            ->assertDontSee('Este enlace permite consultar', false)
            ->assertSee('Paciente Share', false)
            ->assertSee($owner->full_name, false)
            ->assertSee($purchase->formatted_birth_date, false)
            ->assertSee($purchase->formatted_gender, false)
            ->assertSee('GDA-SHARE-001', false)
            ->assertSee('246810', false)
            ->assertSee('Biometria hematica', false)
            ->assertSee('$2,500.00', false)
            ->assertSee('Ayuno 8 horas', false)
            ->assertSee('Quimica sanguinea', false)
            ->assertSee('$1,281.60', false)
            ->assertSee('No tomar alcohol 24 horas', false)
            ->assertSee('$3,781.60 MXN', false)
            ->assertSee('$250.00 MXN', false)
            ->assertSee('$3,531.60 MXN', false)
            ->assertSee('Sucursal Share', false)
            ->assertDontSee('private-results-file.pdf', false)
            ->assertDontSee('private-invoice.pdf', false)
            ->assertDontSee('private-invoice.xml', false)
            ->assertDontSee('XAXX010101000', false)
            ->assertDontSee('private-certificate.pdf', false)
            ->assertDontSee('secret-gateway-token', false)
            ->assertDontSee('gw-private-123', false)
            ->assertDontSee('provider-private-456', false)
            ->assertDontSee('private-reference', false)
            ->assertDontSee('8111111111', false)
            ->assertDontSee($owner->email, false)
            ->assertDontSee((string) $owner->phone, false)
            ->assertDontSee('total_cents', false)
            ->assertDontSee('transactions', false)
            ->assertDontSee('invoiceRequest', false)
            ->assertDontSee('invoice_requestable', false);
    }

    public function test_public_share_exposes_phase_three_payload_as_strict_allowlist(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/LaboratoryOrder')
                ->where('laboratoryOrder.order.famedic_folio', 'GDA-SHARE-001')
                ->where('laboratoryOrder.order.laboratory_order_folio', 'GDA-SHARE-001')
                ->where('laboratoryOrder.order.consecutive', '246810')
                ->where('laboratoryOrder.order.brand.value', 'olab')
                ->where('laboratoryOrder.order.brand.stores_url', route('laboratory-stores.index', ['brand' => 'olab']))
                ->where('laboratoryOrder.order.brand.stores_count', 1)
                ->where('laboratoryOrder.order.brand.featured_stores', [])
                ->where('laboratoryOrder.purchaser.name', $owner->full_name)
                ->where('laboratoryOrder.patient.full_name', 'Paciente Share Seguro')
                ->where('laboratoryOrder.patient.birth_date', '1991-05-14')
                ->where('laboratoryOrder.patient.formatted_birth_date', $purchase->formatted_birth_date)
                ->where('laboratoryOrder.patient.gender', Gender::FEMALE->value)
                ->where('laboratoryOrder.patient.formatted_gender', 'Femenino')
                ->where('laboratoryOrder.pricing.currency', 'MXN')
                ->where('laboratoryOrder.pricing.subtotal', '$3,781.60 MXN')
                ->where('laboratoryOrder.pricing.coupon_discount', '$250.00 MXN')
                ->where('laboratoryOrder.pricing.total', '$3,531.60 MXN')
                ->where('laboratoryOrder.appointment.requires_appointment', true)
                ->where('laboratoryOrder.appointment.has_appointment', true)
                ->where('laboratoryOrder.appointment.status', 'confirmed')
                ->where('laboratoryOrder.store.google_maps_url', 'https://maps.example.test/share')
                ->where('laboratoryOrder.studies.0.name', 'Biometria hematica')
                ->where('laboratoryOrder.studies.0.indications', 'Ayuno 8 horas')
                ->where('laboratoryOrder.studies.0.price', '$2,500.00 MXN')
                ->where('laboratoryOrder.studies.1.name', 'Quimica sanguinea')
                ->where('laboratoryOrder.studies.1.indications', "No tomar alcohol 24 horas\nPresentarse hidratado")
                ->where('laboratoryOrder.studies.1.price', '$1,281.60 MXN')
                ->missing('laboratoryOrder.id')
                ->missing('laboratoryOrder.notice')
                ->missing('laboratoryOrder.purchaser.email')
                ->missing('laboratoryOrder.purchaser.phone')
                ->missing('laboratoryOrder.purchaser.customer_id')
                ->missing('laboratoryOrder.purchaser.user_id')
                ->missing('laboratoryOrder.patient.phone')
                ->missing('laboratoryOrder.patient.email')
                ->missing('laboratoryOrder.transactions')
                ->missing('laboratoryOrder.invoice')
                ->missing('laboratoryOrder.invoiceRequest')
                ->missing('laboratoryOrder.results')
                ->missing('laboratoryOrder.pdf_base64'));
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

    public function test_public_share_renders_server_side_preview_meta_tags(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture();
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $response = $this->get($url);
        $html = $response->getContent();
        $head = Str::between($html, '<head>', '</head>');
        $title = 'Orden de compra de laboratorio | FAMEDIC';
        $description = 'Consulta la información de tu orden de laboratorio, estudios solicitados, cita e indicaciones de preparación.';
        $image = asset('images/og/famedic-og.png');

        $response->assertOk();
        $this->assertStringContainsString('<title inertia>'.$title.'</title>', $head);
        $this->assertStringContainsString('<meta name="description" content="'.$description.'">', $head);
        $this->assertStringContainsString('<meta property="og:title" content="'.$title.'">', $head);
        $this->assertStringContainsString('<meta property="og:description" content="'.$description.'">', $head);
        $this->assertStringContainsString('<meta property="og:image" content="'.$image.'">', $head);
        $this->assertStringContainsString('<meta property="og:url" content="'.$url.'">', $head);
        $this->assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $head);
        $this->assertStringNotContainsString($purchase->full_name, $head);
        $this->assertStringNotContainsString($purchase->gda_order_id, $head);
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

    public function test_public_share_without_coupon_omits_coupon_discount(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture(couponDiscountCents: 0);
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->get($url)
            ->assertOk()
            ->assertDontSee('Descuento por cupon', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/LaboratoryOrder')
                ->where('laboratoryOrder.pricing.coupon_discount', null)
                ->where('laboratoryOrder.pricing.subtotal', '$3,781.60 MXN')
                ->where('laboratoryOrder.pricing.total', '$3,781.60 MXN'));
    }

    public function test_public_share_without_appointment_links_to_brand_store_directory(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture(
            couponDiscountCents: 0,
            withAppointment: false,
            requiresAppointment: false,
        );
        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->get($url)
            ->assertOk()
            ->assertDontSee('La sucursal esta pendiente por confirmar.', false)
            ->assertDontSee('Sucursal pendiente', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/LaboratoryOrder')
                ->where('laboratoryOrder.appointment.requires_appointment', false)
                ->where('laboratoryOrder.appointment.has_appointment', false)
                ->where('laboratoryOrder.appointment.status', 'not_required')
                ->where('laboratoryOrder.store', null)
                ->where('laboratoryOrder.order.brand.stores_count', 1)
                ->where('laboratoryOrder.order.brand.featured_stores.0.name', 'Sucursal Share')
                ->where('laboratoryOrder.order.brand.featured_stores.0.address', 'Av. Prueba 100')
                ->where('laboratoryOrder.order.brand.featured_stores.0.phone', '8187654321')
                ->where('laboratoryOrder.order.brand.featured_stores.0.google_maps_url', 'https://maps.example.test/share')
                ->where('laboratoryOrder.order.brand.featured_stores.0.hours', 'Lun-vie: 7:00 a 15:00 · Sáb: 8:00 a 12:00 · Dom: Cerrado')
                ->where('laboratoryOrder.order.brand.stores_url', route('laboratory-stores.index', ['brand' => 'olab'])));
    }

    public function test_public_share_without_appointment_exposes_limited_sanitized_featured_stores(): void
    {
        [$owner, $purchase] = $this->createPurchaseFixture(
            couponDiscountCents: 0,
            withAppointment: false,
            requiresAppointment: false,
        );

        foreach ([
            ['name' => 'Alpha Norte', 'address' => 'Av. Alfa 10', 'google_maps_url' => 'https://maps.example.test/alpha'],
            ['name' => 'Beta Centro', 'address' => 'Av. Beta 20', 'google_maps_url' => 'javascript:alert(1)'],
            ['name' => 'Epsilon Sur', 'address' => 'Av. Epsilon 30', 'google_maps_url' => 'http://maps.example.test/epsilon'],
            ['name' => 'Gamma Poniente', 'address' => 'Av. Gamma 40', 'google_maps_url' => 'https://maps.example.test/gamma'],
            ['name' => 'Zeta Fuera Del Corte', 'address' => 'Av. Zeta 50', 'google_maps_url' => 'https://maps.example.test/zeta'],
        ] as $store) {
            LaboratoryStore::query()->create([
                'brand' => LaboratoryBrand::OLAB,
                'state' => 'Nuevo Leon',
                'is_active' => true,
                'weekly_hours' => '7:00 a 15:00',
                'saturday_hours' => '8:00 a 12:00',
                'sunday_hours' => 'Cerrado',
                'phone' => '8180000000',
                ...$store,
            ]);
        }

        LaboratoryStore::query()->create([
            'name' => 'Inactiva OLAB',
            'brand' => LaboratoryBrand::OLAB,
            'state' => 'Nuevo Leon',
            'is_active' => false,
            'address' => 'Av. Inactiva 1',
            'weekly_hours' => '7:00 a 15:00',
            'saturday_hours' => '8:00 a 12:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.example.test/inactiva',
        ]);

        LaboratoryStore::query()->create([
            'name' => 'Otra Marca',
            'brand' => LaboratoryBrand::SWISSLAB,
            'state' => 'Nuevo Leon',
            'is_active' => true,
            'address' => 'Av. Otra 1',
            'weekly_hours' => '7:00 a 15:00',
            'saturday_hours' => '8:00 a 12:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.example.test/otra',
        ]);

        $url = (string) $this->actingAs($owner)
            ->postJson(route('laboratory-purchases.shares.store', $purchase))
            ->json('url');

        $this->get($url)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Shared/LaboratoryOrder')
                ->where('laboratoryOrder.order.brand.stores_count', 6)
                ->where('laboratoryOrder.order.brand.featured_stores.0.name', 'Alpha Norte')
                ->where('laboratoryOrder.order.brand.featured_stores.0.address', 'Av. Alfa 10')
                ->where('laboratoryOrder.order.brand.featured_stores.0.google_maps_url', 'https://maps.example.test/alpha')
                ->where('laboratoryOrder.order.brand.featured_stores.1.name', 'Beta Centro')
                ->where('laboratoryOrder.order.brand.featured_stores.1.google_maps_url', null)
                ->where('laboratoryOrder.order.brand.featured_stores.2.name', 'Epsilon Sur')
                ->where('laboratoryOrder.order.brand.featured_stores.2.google_maps_url', 'http://maps.example.test/epsilon')
                ->where('laboratoryOrder.order.brand.featured_stores.3.name', 'Gamma Poniente')
                ->where('laboratoryOrder.order.brand.featured_stores.3.hours', 'Lun-vie: 7:00 a 15:00 · Sáb: 8:00 a 12:00 · Dom: Cerrado')
                ->missing('laboratoryOrder.order.brand.featured_stores.4')
                ->missing('laboratoryOrder.order.brand.featured_stores.0.id')
                ->missing('laboratoryOrder.order.brand.featured_stores.0.latitude')
                ->missing('laboratoryOrder.order.brand.featured_stores.0.longitude')
                ->missing('laboratoryOrder.order.brand.featured_stores.0.raw_import_payload'));
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
    private function createPurchaseFixture(
        ?string $results = null,
        int $couponDiscountCents = 25000,
        bool $withAppointment = true,
        bool $requiresAppointment = true,
    ): array {
        $owner = $this->createCustomerUser();

        $purchase = LaboratoryPurchase::query()->create([
            'brand' => LaboratoryBrand::OLAB,
            'gda_order_id' => 'GDA-SHARE-001',
            'gda_consecutivo' => 246810,
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
            'total_cents' => 378160,
            'coupon_discount_cents' => $couponDiscountCents,
            'results' => $results,
            'customer_id' => $owner->customer->id,
        ]);

        LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB,
            'gda_id' => 'BH-001',
            'name' => 'Biometria hematica',
            'requires_appointment' => $requiresAppointment,
        ]);

        LaboratoryTest::factory()->create([
            'brand' => LaboratoryBrand::OLAB,
            'gda_id' => 'QS-001',
            'name' => 'Quimica sanguinea',
            'requires_appointment' => $requiresAppointment,
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
            'price_cents' => 250000,
        ]);

        LaboratoryPurchaseItem::query()->create([
            'laboratory_purchase_id' => $purchase->id,
            'gda_id' => 'QS-001',
            'name' => 'Quimica sanguinea',
            'indications' => "No tomar alcohol 24 horas\nPresentarse hidratado",
            'feature_list' => [
                ['name' => 'Glucosa'],
                ['name' => 'Urea'],
            ],
            'price_cents' => 128160,
        ]);

        $store = LaboratoryStore::query()->create([
            'name' => 'Sucursal Share',
            'brand' => LaboratoryBrand::OLAB,
            'state' => 'Nuevo Leon',
            'is_active' => true,
            'address' => 'Av. Prueba 100',
            'weekly_hours' => '7:00 a 15:00',
            'saturday_hours' => '8:00 a 12:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.example.test/share',
            'phone' => '8187654321',
        ]);

        if ($withAppointment) {
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
        }

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
            'transaction_amount_cents' => 353160,
            'payment_method' => 'stripe',
            'reference_id' => 'private-reference',
            'payment_status' => 'completed',
            'gateway_transaction_id' => 'gw-private-123',
            'provider_order_id' => 'provider-private-456',
            'gateway_token' => 'secret-gateway-token',
        ]);

        $purchase->transactions()->attach($transaction);
    }
}
