<?php

namespace Tests\Feature\Marketing;

use App\Actions\Marketing\RecordMarketingCampaignConversionAction;
use App\Enums\LaboratoryBrand;
use App\Enums\MarketingCampaignConversionStatus;
use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignAttribution;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignVisit;
use App\Models\MarketingCampaignVisitorIdentity;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Marketing\MarketingCampaignAttributionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once dirname(__DIR__, 2).'/Unit/Marketing/marketingCampaignIsolatedSchema.php';

class MarketingCampaignConversionTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;
        parent::setUp();

        bootstrapIsolatedMarketingCampaignSchema();
    }

    protected function tearDown(): void
    {
        tearDownIsolatedMarketingCampaignSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function registra_conversion_de_compra_laboratorio_atribuida_activa(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 50_000);
        $this->attachTransaction($purchase, amountCents: 37_500);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::Created, $result->status);
        $this->assertDatabaseHas('marketing_campaign_conversions', [
            'marketing_campaign_attribution_id' => $cycle['attribution']->id,
            'customer_id' => $customer->id,
            'user_id' => $customer->user_id,
            'conversion_type' => MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE,
            'purchase_id' => $purchase->id,
            'currency' => 'MXN',
            'amount_cents' => 37_500,
        ]);
    }

    #[Test]
    public function conserva_snapshot_de_first_y_last_distintos(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer, distinctLastVisit: true);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);

        $conversion = MarketingCampaignConversion::query()->firstOrFail();
        $this->assertSame($cycle['firstVisit']->id, $conversion->first_visit_id);
        $this->assertSame($cycle['lastVisit']->id, $conversion->last_visit_id);
        $this->assertSame($cycle['firstLink']->id, $conversion->first_link_id);
        $this->assertSame($cycle['lastLink']->id, $conversion->last_link_id);
    }

    #[Test]
    public function copia_utm_y_click_ids_desde_la_ultima_visita(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer, distinctLastVisit: true, lastVisitAttributes: [
            'utm_source' => 'meta',
            'utm_medium' => 'paid-social',
            'utm_campaign' => 'lab-septiembre',
            'utm_term' => 'glucosa',
            'utm_content' => 'ad-a',
            'gclid' => 'gclid-123',
            'fbclid' => 'fbclid-456',
        ]);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);

        $this->assertDatabaseHas('marketing_campaign_conversions', [
            'utm_source' => 'meta',
            'utm_medium' => 'paid-social',
            'utm_campaign' => 'lab-septiembre',
            'utm_term' => 'glucosa',
            'utm_content' => 'ad-a',
            'gclid' => 'gclid-123',
            'fbclid' => 'fbclid-456',
        ]);
    }

    #[Test]
    public function usa_monto_cobrado_de_transaccion_y_no_total_bruto_de_compra(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 50_000);
        $this->attachTransaction($purchase, amountCents: 30_000, details: [
            'original_total_cents' => 50_000,
            'coupon_amount_cents' => 20_000,
            'amount_charged_cents' => 30_000,
        ]);

        $this->record($purchase);

        $this->assertSame(30_000, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function suma_multiples_transacciones_exitosas_aplicadas_a_la_compra(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 50_000);
        $this->attachTransaction($purchase, amountCents: 30_000, status: 'completed');
        $this->attachTransaction($purchase, amountCents: 20_000, status: 'captured');

        $this->record($purchase);

        $this->assertSame(50_000, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function ignora_reintento_fallido_y_suma_solo_transacciones_aprobadas(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 50_000);
        $this->attachTransaction($purchase, amountCents: 50_000, status: 'failed');
        $this->attachTransaction($purchase, amountCents: 35_000, status: 'completed');

        $this->record($purchase);

        $this->assertSame(35_000, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function suma_solo_dinero_cobrado_en_pago_dividido_con_credito(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 50_000);
        $this->attachTransaction($purchase, amountCents: 0, method: 'coupon_balance', status: 'credit');
        $this->attachTransaction($purchase, amountCents: 35_000, status: 'completed');

        $this->record($purchase);

        $this->assertSame(35_000, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function usa_amount_charged_cents_como_respaldo_si_la_transaccion_no_tiene_monto(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 40_000);
        $this->attachTransaction($purchase, amountCents: null, details: [
            'amount_charged_cents' => 22_000,
        ]);

        $this->record($purchase);

        $this->assertSame(22_000, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function registra_conversion_de_compra_cubierta_por_saldo_con_monto_cero(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer, totalCents: 10_000);
        $this->attachTransaction($purchase, amountCents: 0, status: 'credit', details: [
            'amount_charged_cents' => 0,
        ]);

        $this->record($purchase);

        $this->assertSame(0, MarketingCampaignConversion::query()->value('amount_cents'));
    }

    #[Test]
    public function segunda_ejecucion_devuelve_already_recorded_sin_duplicar(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);
        $second = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::AlreadyRecorded, $second->status);
        $this->assertNotNull($second->conversion);
        $this->assertSame(1, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function no_crea_conversion_si_no_hay_atribucion(): void
    {
        $customer = $this->makeCustomer();
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::NotAttributed, $result->status);
        $this->assertSame(0, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function ignora_atribucion_expirada_al_momento_de_conversion(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer, touchedAt: now()->subDays(40), expiresAt: now()->subDay());
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::NotAttributed, $result->status);
        $this->assertSame(0, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function ignora_atribucion_tocada_despues_de_la_compra(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer, touchedAt: now()->addHour(), expiresAt: now()->addDays(30));
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::NotAttributed, $result->status);
    }

    #[Test]
    public function no_usa_atribucion_de_otro_customer(): void
    {
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer();
        $this->makeAttributionCycle($otherCustomer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::NotAttributed, $result->status);
        $this->assertSame(0, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function usa_visitas_identificadas_si_la_atribucion_no_tiene_customer_directo(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer);
        MarketingCampaignAttribution::query()
            ->whereKey($cycle['attribution']->id)
            ->update(['customer_id' => null, 'user_id' => null]);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::Created, $result->status);
        $this->assertSame($cycle['attribution']->id, MarketingCampaignConversion::query()->value('marketing_campaign_attribution_id'));
    }

    #[Test]
    public function marca_invalida_si_una_visita_del_ciclo_pertenece_a_otro_customer(): void
    {
        $customer = $this->makeCustomer();
        $otherCustomer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer);
        MarketingCampaignVisit::query()
            ->whereKey($cycle['lastVisit']->id)
            ->update(['customer_id' => $otherCustomer->id]);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::InvalidAttribution, $result->status);
        $this->assertSame(0, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function con_multiples_atribuciones_activas_elige_la_mas_reciente_de_forma_deterministica(): void
    {
        Log::spy();
        $customer = $this->makeCustomer();
        $older = $this->makeAttributionCycle($customer, touchedAt: now()->subDays(2));
        $newer = $this->makeAttributionCycle($customer, touchedAt: now()->subHour(), slug: 'newer-link');
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);

        $this->assertSame($newer['attribution']->id, MarketingCampaignConversion::query()->value('marketing_campaign_attribution_id'));
        $this->assertNotSame($older['attribution']->id, MarketingCampaignConversion::query()->value('marketing_campaign_attribution_id'));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => $message === 'marketing_campaign_conversion_multiple_active_attributions'
            && $context['selected_marketing_campaign_attribution_id'] === $newer['attribution']->id);
    }

    #[Test]
    public function atribucion_sin_ultima_visita_es_invalida(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer);
        $cycle['attribution']->forceFill(['last_visit_id' => null])->save();
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::InvalidAttribution, $result->status);
    }

    #[Test]
    public function compra_sin_transaccion_exitosa_es_invalida(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::InvalidAttribution, $result->status);
        $this->assertSame(0, MarketingCampaignConversion::query()->count());
    }

    #[Test]
    public function transaccion_fallida_no_genera_conversion(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase, status: 'failed');

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::InvalidAttribution, $result->status);
    }

    #[Test]
    public function paypal_capturado_es_transaccion_exitosa_para_conversion(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase, method: 'paypal', status: 'captured');

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::Created, $result->status);
    }

    #[Test]
    public function la_conversion_es_inmutable_por_modelo(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);
        $conversion = MarketingCampaignConversion::query()->firstOrFail();

        $this->assertFalse($conversion->update(['amount_cents' => 1]));
        $this->assertFalse($conversion->delete());
        $this->assertSame(10_000, $conversion->fresh()->amount_cents);
    }

    #[Test]
    public function el_unique_de_base_de_datos_impide_doble_conversion_por_compra(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);

        $this->expectException(\Illuminate\Database\QueryException::class);

        MarketingCampaignConversion::query()->create([
            'marketing_campaign_attribution_id' => $cycle['attribution']->id,
            'marketing_campaign_visitor_identity_id' => $cycle['identity']->id,
            'first_campaign_id' => $cycle['campaign']->id,
            'first_link_id' => $cycle['firstLink']->id,
            'first_visit_id' => $cycle['firstVisit']->id,
            'last_campaign_id' => $cycle['campaign']->id,
            'last_link_id' => $cycle['lastLink']->id,
            'last_visit_id' => $cycle['lastVisit']->id,
            'user_id' => $customer->user_id,
            'customer_id' => $customer->id,
            'conversion_type' => MarketingCampaignConversion::TYPE_LABORATORY_PURCHASE,
            'purchase_id' => $purchase->id,
            'currency' => 'MXN',
            'amount_cents' => 10_000,
            'converted_at' => now(),
            'created_at' => now(),
        ]);
    }

    #[Test]
    public function fallo_interno_devuelve_failed_y_no_propaga_excepcion(): void
    {
        $customer = $this->makeCustomer();
        $purchase = $this->makePurchase($customer);

        Schema::dropIfExists('marketing_campaign_conversions');

        $result = $this->record($purchase);

        $this->assertSame(MarketingCampaignConversionStatus::Failed, $result->status);
    }

    #[Test]
    public function no_modifica_la_ventana_de_atribucion_al_registrar_conversion(): void
    {
        $customer = $this->makeCustomer();
        $cycle = $this->makeAttributionCycle($customer, touchedAt: now()->subDay());
        $expiresAt = $cycle['attribution']->expires_at->copy();
        $lastTouchedAt = $cycle['attribution']->last_touched_at->copy();
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase);

        $this->record($purchase);
        $cycle['attribution']->refresh();

        $this->assertTrue($expiresAt->equalTo($cycle['attribution']->expires_at));
        $this->assertTrue($lastTouchedAt->equalTo($cycle['attribution']->last_touched_at));
    }

    #[Test]
    public function compra_cancelada_o_reembolsada_no_muta_snapshot_existente(): void
    {
        $customer = $this->makeCustomer();
        $this->makeAttributionCycle($customer);
        $purchase = $this->makePurchase($customer);
        $this->attachTransaction($purchase, amountCents: 10_000, status: 'completed');

        $this->record($purchase);
        $conversion = MarketingCampaignConversion::query()->firstOrFail();

        $purchase->delete();
        $this->attachTransaction($purchase, amountCents: 10_000, status: 'refunded');

        $this->assertSame(1, MarketingCampaignConversion::query()->count());
        $this->assertSame(10_000, $conversion->fresh()->amount_cents);
        $this->assertNull($conversion->fresh()->updated_at);
    }

    private function makeCustomer(): Customer
    {
        $user = User::factory()->create();

        return Customer::query()->create([
            'user_id' => $user->id,
        ]);
    }

    private function makePurchase(Customer $customer, int $totalCents = 10_000): LaboratoryPurchase
    {
        return LaboratoryPurchase::query()->create([
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => '0',
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Marketing',
            'phone' => '5555555555',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'CDMX',
            'city' => 'CDMX',
            'zipcode' => '01000',
            'total_cents' => $totalCents,
            'customer_id' => $customer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function attachTransaction(
        LaboratoryPurchase $purchase,
        ?int $amountCents = 10_000,
        string $method = 'efevoopay',
        string $status = 'completed',
        array $details = [],
    ): Transaction {
        $transaction = Transaction::query()->create([
            'transaction_amount_cents' => $amountCents,
            'payment_method' => $method,
            'gateway' => $method,
            'reference_id' => 'tx-'.$purchase->id.'-'.str()->random(6),
            'payment_status' => $status,
            'details' => $details,
        ]);

        $purchase->transactions()->attach($transaction);

        return $transaction;
    }

    /**
     * @return array{
     *     campaign: MarketingCampaign,
     *     firstLink: MarketingCampaignLink,
     *     lastLink: MarketingCampaignLink,
     *     identity: MarketingCampaignVisitorIdentity,
     *     attribution: MarketingCampaignAttribution,
     *     firstVisit: MarketingCampaignVisit,
     *     lastVisit: MarketingCampaignVisit
     * }
     */
    private function makeAttributionCycle(
        Customer $customer,
        ?\DateTimeInterface $touchedAt = null,
        ?\DateTimeInterface $expiresAt = null,
        bool $distinctLastVisit = false,
        array $lastVisitAttributes = [],
        string $slug = 'conversion-link',
    ): array {
        $touchedAt ??= now()->subHour();
        $expiresAt ??= now()->addDays(30);
        $campaign = MarketingCampaign::factory()->create([
            'status' => MarketingCampaignStatus::Active,
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $firstLink = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'status' => MarketingCampaignLinkStatus::Active,
            'slug' => $slug.'-first-'.str()->random(6),
            'target_type' => MarketingCampaignTargetType::Brand,
            'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
            'starts_at' => null,
            'ends_at' => null,
        ]);
        $lastLink = $distinctLastVisit
            ? MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
                'status' => MarketingCampaignLinkStatus::Active,
                'slug' => $slug.'-last-'.str()->random(6),
                'target_type' => MarketingCampaignTargetType::Brand,
                'target_payload' => ['brand' => LaboratoryBrand::OLAB->value],
                'starts_at' => null,
                'ends_at' => null,
            ])
            : $firstLink;
        $tokenHash = app(MarketingCampaignAttributionTokenService::class)->hash(str()->uuid()->toString());
        $identity = MarketingCampaignVisitorIdentity::query()->create([
            'visitor_token_hash' => $tokenHash,
        ]);
        $firstVisit = MarketingCampaignVisit::query()->create([
            'marketing_campaign_id' => $campaign->id,
            'marketing_campaign_link_id' => $firstLink->id,
            'marketing_campaign_attribution_id' => null,
            'visitor_token_hash' => $tokenHash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'user_id' => $customer->user_id,
            'customer_id' => $customer->id,
            'landing_path' => '/c/'.$firstLink->slug,
            'visited_at' => $touchedAt,
            'created_at' => $touchedAt,
        ]);
        if ($distinctLastVisit) {
            $lastVisit = MarketingCampaignVisit::query()->create(array_merge([
                'marketing_campaign_id' => $campaign->id,
                'marketing_campaign_link_id' => $lastLink->id,
                'marketing_campaign_attribution_id' => null,
                'visitor_token_hash' => $tokenHash,
                'marketing_campaign_visitor_identity_id' => $identity->id,
                'user_id' => $customer->user_id,
                'customer_id' => $customer->id,
                'landing_path' => '/c/'.$lastLink->slug,
                'visited_at' => $touchedAt,
                'created_at' => $touchedAt,
            ], $lastVisitAttributes));
        } else {
            $firstVisit->forceFill($lastVisitAttributes)->save();
            $lastVisit = $firstVisit;
        }

        $attribution = MarketingCampaignAttribution::query()->create([
            'visitor_token_hash' => $tokenHash,
            'marketing_campaign_visitor_identity_id' => $identity->id,
            'first_campaign_id' => $campaign->id,
            'first_link_id' => $firstLink->id,
            'first_visit_id' => $firstVisit->id,
            'last_campaign_id' => $campaign->id,
            'last_link_id' => $lastLink->id,
            'last_visit_id' => $lastVisit->id,
            'first_touched_at' => $touchedAt,
            'last_touched_at' => $touchedAt,
            'expires_at' => $expiresAt,
            'user_id' => $customer->user_id,
            'customer_id' => $customer->id,
        ]);

        MarketingCampaignVisit::query()
            ->whereKey($firstVisit->id)
            ->update(['marketing_campaign_attribution_id' => $attribution->id]);
        MarketingCampaignVisit::query()
            ->whereKey($lastVisit->id)
            ->update(['marketing_campaign_attribution_id' => $attribution->id]);

        return compact('campaign', 'firstLink', 'lastLink', 'identity', 'attribution', 'firstVisit', 'lastVisit');
    }

    private function record(LaboratoryPurchase $purchase)
    {
        return app(RecordMarketingCampaignConversionAction::class)($purchase);
    }
}
