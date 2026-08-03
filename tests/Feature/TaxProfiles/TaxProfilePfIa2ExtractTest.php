<?php

namespace Tests\Feature\TaxProfiles;

use App\Actions\CreateInvoiceRequestAction;
use App\Actions\TaxProfiles\CreateTaxProfileAction;
use App\Actions\TaxProfiles\ExtractTaxProfileFromConstanciaAction;
use App\Actions\TaxProfiles\UpdateTaxProfileAction;
use App\DataTransferObjects\TaxProfiles\ConstanciaExtractionResult;
use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\ConstanciaFiscalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TaxProfilePfIa2ExtractTest extends TestCase
{
    private string $storageRoot;

    private string $tmpExtractDir;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-pfia2-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);
        $this->tmpExtractDir = storage_path('app/tmp/tax-profile-extract');

        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
            'app.env' => 'testing',
            'services.openai.key' => 'test-key-not-real',
            'services.openai.model' => 'gpt-4o-mini',
            'services.openai.tax_profile_model' => null,
            'services.openai.timeout' => 5,
            'taxregimes.regimes' => config('taxregimes.regimes'),
            'taxregimes.uses' => [
                'G03' => 'Gastos en general.',
                'D01' => 'Honorarios médicos.',
            ],
        ]);
        Storage::forgetDisk('local');
        Cache::flush();

        $this->bootstrapSchema();
        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        $this->cleanupDir($this->storageRoot);
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function usuario_autenticado_procesa_constancia_valida(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual();

        $service = \Mockery::mock(ConstanciaFiscalService::class)->makePartial();
        $service->shouldReceive('extractText')->andReturn($this->sampleCsfText());
        $service->shouldReceive('extractDeterministicData')->andReturn($this->sampleLocalData());
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertOk()->assertJsonPath('success', true);
        $response->assertJsonPath('data.tipo_persona', 'fisica');
        $response->assertJsonPath('data.rfc', 'MEBE931209BI2');
        $response->assertJsonMissingPath('data.curp');
        $this->assertSame(0, TaxProfile::count());
        Http::assertSentCount(1);
    }

    #[Test]
    public function usuario_no_autenticado_no_puede_extraer(): void
    {
        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertUnauthorized();
    }

    #[Test]
    public function archivo_mayor_a_5mb_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $big = UploadedFile::fake()->createWithContent(
            'big.pdf',
            "%PDF-1.4\n".str_repeat('A', 5 * 1024 * 1024 + 100)
        );

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $big,
        ]);

        $response->assertStatus(422);
        $this->assertTrue(
            $response->json('success') === false
            || $response->json('errors') !== null
            || isset($response->json()['message'])
        );
    }

    #[Test]
    public function mime_o_extension_invalidos_son_rechazados(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => UploadedFile::fake()->createWithContent('nota.txt', 'hola mundo'),
        ]);

        $response->assertStatus(422);
    }

    #[Test]
    public function archivo_sin_encabezado_pdf_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => UploadedFile::fake()->createWithContent(
                'falso.pdf',
                'NOESUNPDF'.str_repeat('x', 100)
            ),
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('PDF', (string) $response->json('message') ?: json_encode($response->json('errors')));
    }

    #[Test]
    public function pdf_corrupto_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andThrow(
            ConstanciaExtractionException::invalidDocument('No pudimos leer el PDF de la constancia. Verifica que no esté corrupto o captura los datos manualmente.')
        );
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload('%PDF-1.4 corrupted stream'),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', ConstanciaExtractionException::INVALID_DOCUMENT);
        $this->assertStringNotContainsString('OpenAI', (string) $response->json('message'));
    }

    #[Test]
    public function pdf_protegido_se_maneja_sin_detalles_internos(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andThrow(ConstanciaExtractionException::protectedDocument());
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::PROTECTED);
        $encoded = $response->getContent();
        $this->assertStringNotContainsString('password', strtolower($encoded));
        $this->assertStringNotContainsString('stack', strtolower($encoded));
    }

    #[Test]
    public function pdf_sin_texto_se_devuelve_como_ilegible(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andThrow(ConstanciaExtractionException::unreadable());
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::UNREADABLE);
    }

    #[Test]
    public function documento_no_csf_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn(str_repeat('Factura de compra aleatoria sin datos fiscales ', 5));
        $service->shouldReceive('extractDeterministicData')->andReturn([
            'rfc' => null,
            'nombre' => null,
            'razon_social' => null,
            'codigo_postal' => null,
            'regimen_fiscal' => null,
            'tipo_persona' => null,
        ]);
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::NOT_CSF);
    }

    #[Test]
    public function extraccion_completa_devuelve_valores_normalizados(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual([
            'tax_regime' => '605',
            'zipcode' => '79980',
            'name' => 'EULALIO MEDINA BARRAGAN',
        ]);
        $this->bindLocalExtraction();

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.taxpayer_type', 'individual')
            ->assertJsonPath('data.fields.rfc.value', 'MEBE931209BI2')
            ->assertJsonPath('data.missing_fields', []);
    }

    #[Test]
    public function extraccion_parcial_informa_faltantes(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual([
            'zipcode' => null,
            'codigo_postal_original' => null,
            'tax_regime' => null,
            'regimen_fiscal_original' => null,
            'status' => 'partial',
            'missing_fields' => ['zipcode', 'tax_regime'],
        ]);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn($this->sampleCsfText());
        $service->shouldReceive('extractDeterministicData')->andReturn([
            'rfc' => 'MEBE931209BI2',
            'nombre' => 'EULALIO MEDINA BARRAGAN',
            'razon_social' => 'EULALIO MEDINA BARRAGAN',
            'codigo_postal' => null,
            'regimen_fiscal' => null,
            'tipo_persona' => 'fisica',
            'tipo_persona_confianza' => 90,
        ]);
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'partial');
        $this->assertNotEmpty($response->json('data.missing_fields'));
    }

    #[Test]
    public function rfc_moral_de_12_caracteres_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual([
            'rfc' => 'ABC010101XX0',
            'taxpayer_type' => 'individual',
            'tipo_persona' => 'fisica',
        ]);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn($this->sampleCsfText('ABC010101XX0', moral: true));
        $service->shouldReceive('extractDeterministicData')->andReturn([
            'rfc' => 'ABC010101XX0',
            'nombre' => 'EMPRESA SA DE CV',
            'razon_social' => 'EMPRESA SA DE CV',
            'codigo_postal' => '64000',
            'regimen_fiscal' => 'General de Ley Personas Morales',
            'tipo_persona' => 'moral',
        ]);
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::LEGAL_ENTITY_NOT_ALLOWED)
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function etiqueta_persona_moral_es_rechazada(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn(
            $this->sampleCsfText().' Tipo de contribuyente: Persona Moral '
        );
        $service->shouldReceive('extractDeterministicData')->andReturn($this->sampleLocalData());
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::LEGAL_ENTITY_NOT_ALLOWED);
        Http::assertNothingSent();
    }

    #[Test]
    public function openai_fisica_con_rfc_12_es_rechazado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual([
            'rfc' => 'EME910101AB1',
            'taxpayer_type' => 'individual',
            'tipo_persona' => 'fisica',
        ]);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn($this->sampleCsfText('EME910101AB1'));
        $service->shouldReceive('extractDeterministicData')->andReturn([
            'rfc' => 'EME910101AB1',
            'nombre' => 'EMPRESA',
            'razon_social' => 'EMPRESA',
            'codigo_postal' => '64000',
            'regimen_fiscal' => '605',
            'tipo_persona' => 'fisica',
        ]);
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::LEGAL_ENTITY_NOT_ALLOWED);
    }

    #[Test]
    public function resultado_inconsistente_no_es_confirmable(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual([
            'rfc' => 'MEBE931209BI2',
            'taxpayer_type' => 'legal_entity',
            'tipo_persona' => 'fisica',
            'document_classification' => 'csf_individual',
        ]);
        $this->bindLocalExtraction();

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::INCONSISTENT_DATA)
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function timeout_del_proveedor_devuelve_error_controlado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'timeout']], 504),
        ]);
        $this->bindLocalExtraction([
            'codigo_postal' => null,
            'regimen_fiscal' => null,
        ]);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422);
        $this->assertContains($response->json('code'), [
            ConstanciaExtractionException::EXTRACTION_TIMEOUT,
            ConstanciaExtractionException::EXTRACTION_FAILED,
        ]);
        $this->assertStringNotContainsString('OpenAI', (string) $response->json('message'));
    }

    #[Test]
    public function respuesta_estructurada_invalida_devuelve_error_controlado(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => 'no-json']],
                ],
            ], 200),
        ]);
        $this->bindLocalExtraction([
            'codigo_postal' => null,
            'regimen_fiscal' => null,
        ]);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
        $this->assertStringNotContainsString('no-json', (string) $response->getContent());
    }

    #[Test]
    public function falla_ia_con_extraccion_local_suficiente_usa_fallback(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);
        $this->bindLocalExtraction();

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.rfc', 'MEBE931209BI2');
        $this->assertFalse((bool) $response->json('data.ai_assisted'));
        $warnings = $response->json('data.warnings') ?? [];
        $this->assertTrue(collect($warnings)->contains(fn ($w) => str_contains(strtolower($w), 'local')));
    }

    #[Test]
    public function falla_ia_con_extraccion_local_insuficiente_permite_manual(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'boom']], 500),
        ]);
        $this->bindLocalExtraction([
            'codigo_postal' => null,
            'regimen_fiscal' => null,
        ]);

        $response = $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('code', ConstanciaExtractionException::EXTRACTION_FAILED);
    }

    #[Test]
    public function temporal_eliminado_despues_del_exito_y_fallo(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $before = $this->listExtractTemps();

        $this->fakeOpenAiIndividual();
        $this->bindLocalExtraction();
        $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ])->assertOk();

        $afterSuccess = $this->listExtractTemps();
        $this->assertSame($before, $afterSuccess);

        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andThrow(ConstanciaExtractionException::unreadable());
        $this->app->instance(ConstanciaFiscalService::class, $service);

        $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ])->assertStatus(422);

        $this->assertSame($before, $this->listExtractTemps());
    }

    #[Test]
    public function extraccion_no_crea_perfil(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);
        $this->fakeOpenAiIndividual();
        $this->bindLocalExtraction();

        $this->assertSame(0, TaxProfile::count());
        $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload(),
        ])->assertOk();
        $this->assertSame(0, TaxProfile::count());
    }

    #[Test]
    public function store_manual_rechaza_rfc_moral_y_tipo_moral(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        try {
            app(CreateTaxProfileAction::class)(
                name: 'Empresa SA',
                rfc: 'ABC010101XX0',
                zipcode: '64000',
                taxRegime: '601',
                cfdiUse: 'G03',
                fiscalCertificate: $this->pdfUpload(),
                extractedData: ['tipo_persona' => 'moral'],
            );
            $this->fail('Se esperaba rechazo de RFC moral');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('persona moral', strtolower($e->getMessage()));
        }

        $this->assertSame(0, TaxProfile::count());
    }

    #[Test]
    public function store_rechaza_extracted_data_con_tipo_moral(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $request = \App\Http\Requests\TaxProfiles\StoreTaxProfileRequest::create('/tax-profiles', 'POST', [
            'name' => 'Persona',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '605',
            'cfdi_use' => 'G03',
            'tipo_persona' => 'moral',
            'extracted_data' => [
                'tipo_persona' => 'moral',
                'rfc' => 'MEBE931209BI2',
            ],
        ]);
        $request->setUserResolver(fn () => $user);
        $request->files->set('fiscal_certificate', $this->pdfUpload());

        $validator = \Illuminate\Support\Facades\Validator::make(
            $request->all(),
            $request->rules(),
            $request->messages()
        );
        $request->withValidator($validator);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('rfc', $validator->errors()->toArray());
    }

    #[Test]
    public function update_rechaza_convertir_a_moral_y_conserva_inmutabilidad(): void
    {
        [$user, $customer] = $this->makeCustomer();
        $this->actingAs($user);

        $profile = $customer->taxProfiles()->create([
            'name' => 'Persona',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '605',
            'cfdi_use' => 'G03',
            'tipo_persona' => 'fisica',
            'fiscal_certificate' => 'fiscal-certificates/a.pdf',
            'is_default' => true,
        ]);
        Storage::put('fiscal-certificates/a.pdf', '%PDF-1.4 test');

        try {
            app(UpdateTaxProfileAction::class)(
                name: 'Persona',
                rfc: 'ABC010101XX0',
                zipcode: '64000',
                taxRegime: '601',
                cfdiUse: 'G03',
                taxProfile: $profile->fresh(),
            );
            $this->fail('Se esperaba rechazo al convertir a RFC moral');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('persona moral', strtolower($e->getMessage()));
        }

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'name' => 'Paciente',
            'paternal_lastname' => 'P',
            'maternal_lastname' => 'M',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'MTY',
            'zipcode' => '64000',
            'total_cents' => 1000,
        ]);

        app(CreateInvoiceRequestAction::class)($purchase, $profile->fresh(), 'G03');

        $this->expectException(\InvalidArgumentException::class);
        app(UpdateTaxProfileAction::class)(
            name: 'Persona',
            rfc: 'MEBE931209BI2',
            zipcode: '64000',
            taxRegime: '605',
            cfdiUse: 'G03',
            taxProfile: $profile->fresh(),
        );
    }

    #[Test]
    public function nueva_solicitud_factura_rechaza_perfil_moral_historico(): void
    {
        [, $customer] = $this->makeCustomer();
        $profile = $customer->taxProfiles()->create([
            'name' => 'Empresa',
            'rfc' => 'ABC010101XX0',
            'zipcode' => '64000',
            'tax_regime' => '601',
            'cfdi_use' => 'G03',
            'tipo_persona' => 'moral',
            'fiscal_certificate' => 'fiscal-certificates/moral.pdf',
            'is_default' => true,
        ]);
        Storage::put('fiscal-certificates/moral.pdf', '%PDF-1.4 test');

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'name' => 'Paciente',
            'paternal_lastname' => 'P',
            'maternal_lastname' => 'M',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'MTY',
            'zipcode' => '64000',
            'total_cents' => 1000,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(CreateInvoiceRequestAction::class)($purchase, $profile, 'G03');
    }

    #[Test]
    public function primer_perfil_fisico_conserva_is_default_true(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $profile = app(CreateTaxProfileAction::class)(
            name: 'Persona',
            rfc: 'MEBE931209BI2',
            zipcode: '64000',
            taxRegime: '605',
            cfdiUse: 'G03',
            fiscalCertificate: $this->pdfUpload(),
        );

        $this->assertTrue($profile->fresh()->is_default);
        $this->assertSame('fisica', $profile->tipo_persona);
    }

    #[Test]
    public function rate_limit_funciona(): void
    {
        [$user] = $this->makeCustomer('rate-limit@test.local');
        $this->actingAs($user);

        $mock = \Mockery::mock(ExtractTaxProfileFromConstanciaAction::class);
        $mock->shouldReceive('__invoke')->andReturn(new ConstanciaExtractionResult(
            status: 'completed',
            documentClassification: 'csf_individual',
            taxpayerType: 'individual',
            tipoPersona: 'fisica',
            wizardPayload: [
                'rfc' => 'MEBE931209BI2',
                'nombre' => 'TEST',
                'razon_social' => 'TEST',
                'codigo_postal' => '64000',
                'regimen_fiscal' => '605',
                'tipo_persona' => 'fisica',
            ],
            fields: [],
            extractedFields: ['rfc'],
            missingFields: [],
            warnings: [],
        ));
        $this->app->instance(ExtractTaxProfileFromConstanciaAction::class, $mock);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(route('tax-profiles.extract-data'), [
                'fiscal_certificate' => $this->pdfUpload('rl-'.$i),
            ])->assertOk();
        }

        $this->postJson(route('tax-profiles.extract-data'), [
            'fiscal_certificate' => $this->pdfUpload('rl-6'),
        ])->assertStatus(429);
    }

    #[Test]
    public function lock_evita_procesamiento_simultaneo(): void
    {
        [$user] = $this->makeCustomer();
        $this->actingAs($user);

        $contents = "%PDF-1.4\n".str_repeat('contenido de prueba de constancia fiscal ', 10);
        $fingerprint = hash('sha256', $contents);
        $lock = Cache::lock('tax-profile-extract:'.$user->id.':'.$fingerprint, 60);
        $this->assertTrue($lock->get());

        try {
            $service = \Mockery::mock(ConstanciaFiscalService::class);
            $service->shouldReceive('extractText')->never();
            $this->app->instance(ConstanciaFiscalService::class, $service);

            $response = $this->postJson(route('tax-profiles.extract-data'), [
                'fiscal_certificate' => UploadedFile::fake()->createWithContent('constancia.pdf', $contents),
            ]);

            $response->assertStatus(429)
                ->assertJsonPath('code', ConstanciaExtractionException::ALREADY_PROCESSING);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{0: User, 1: Customer}
     */
    private function makeCustomer(string $email = 'pfia2@test.local'): array
    {
        $user = User::query()->create([
            'name' => 'Test',
            'email' => $email,
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $customer = Customer::query()->create(['user_id' => $user->id]);
        $user->setRelation('customer', $customer);

        return [$user, $customer];
    }

    private function pdfUpload(string $salt = 'ok'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'constancia.pdf',
            "%PDF-1.4\n".str_repeat('contenido de prueba de constancia fiscal '.$salt.' ', 8)
        );
    }

    private function sampleCsfText(string $rfc = 'MEBE931209BI2', bool $moral = false): string
    {
        $tipo = $moral ? 'Persona Moral' : 'Persona Física';

        return <<<TXT
        CONSTANCIA DE SITUACION FISCAL
        RFC: {$rfc}
        Tipo de contribuyente: {$tipo}
        Registro Federal de Contribuyentes
        Nombre, denominación o razón social
        EULALIO MEDINA BARRAGAN
        Codigo Postal: 79980
        Regimen de Sueldos y Salarios e Ingresos Asimilados a Salarios
        Estatus: ACTIVO
        TXT;
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleLocalData(): array
    {
        return [
            'rfc' => 'MEBE931209BI2',
            'nombre' => 'EULALIO MEDINA BARRAGAN',
            'razon_social' => 'EULALIO MEDINA BARRAGAN',
            'codigo_postal' => '79980',
            'regimen_fiscal' => 'Régimen de Sueldos y Salarios e Ingresos Asimilados a Salarios',
            'tipo_persona' => 'fisica',
            'fecha_emision' => '2024-01-15',
            'estatus_sat' => 'ACTIVO',
            'tipo_persona_confianza' => 95,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function bindLocalExtraction(array $overrides = []): void
    {
        $service = \Mockery::mock(ConstanciaFiscalService::class);
        $service->shouldReceive('extractText')->andReturn($this->sampleCsfText());
        $service->shouldReceive('extractDeterministicData')->andReturn(array_merge($this->sampleLocalData(), $overrides));
        $this->app->instance(ConstanciaFiscalService::class, $service);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeOpenAiIndividual(array $overrides = []): void
    {
        $payload = array_merge([
            'status' => 'completed',
            'document_classification' => 'csf_individual',
            'taxpayer_type' => 'individual',
            'tipo_persona' => 'fisica',
            'name' => 'EULALIO MEDINA BARRAGAN',
            'razon_social' => 'EULALIO MEDINA BARRAGAN',
            'rfc' => 'MEBE931209BI2',
            'curp' => 'MEBE931209HDFDRL09',
            'zipcode' => '79980',
            'codigo_postal_original' => '79980',
            'tax_regime' => '605',
            'regimen_fiscal_original' => 'Sueldos y Salarios e Ingresos Asimilados a Salarios',
            'domicilio_fiscal' => null,
            'fecha_emision_constancia' => '2024-01-15',
            'fecha_inscripcion' => null,
            'estatus_sat' => 'ACTIVO',
            'actividades_economicas' => null,
            'tipo_persona_confianza' => 95,
            'tipo_persona_detectado_por' => 'openai',
            'extracted_fields' => ['name', 'rfc', 'zipcode', 'tax_regime'],
            'missing_fields' => [],
            'warnings' => [],
            'rejection_reason' => null,
        ], $overrides);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($payload)]],
                ],
            ], 200),
        ]);
    }

    /**
     * @return list<string>
     */
    private function listExtractTemps(): array
    {
        if (! is_dir($this->tmpExtractDir)) {
            return [];
        }

        return array_values(array_filter(scandir($this->tmpExtractDir) ?: [], fn ($f) => ! in_array($f, ['.', '..'], true)));
    }

    private function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($dir);
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoice_requests', 'laboratory_purchases', 'tax_profiles', 'customers', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained();
            $table->string('customerable_type')->nullable();
            $table->unsignedBigInteger('customerable_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tax_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->string('name');
            $table->string('razon_social')->nullable();
            $table->string('rfc')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('codigo_postal_original')->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('regimen_fiscal_original')->nullable();
            $table->string('cfdi_use')->nullable();
            $table->string('fiscal_certificate')->nullable();
            $table->string('tipo_persona')->nullable();
            $table->string('fecha_emision_constancia')->nullable();
            $table->date('fecha_inscripcion')->nullable();
            $table->string('estatus_sat')->nullable();
            $table->text('domicilio_fiscal')->nullable();
            $table->text('actividades_economicas')->nullable();
            $table->integer('tipo_persona_confianza')->default(0);
            $table->string('tipo_persona_detectado_por')->nullable();
            $table->string('hash_constancia')->nullable();
            $table->boolean('verificado_automaticamente')->default(false);
            $table->timestamp('fecha_verificacion')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
            $table->string('name');
            $table->string('paternal_lastname');
            $table->string('maternal_lastname');
            $table->string('phone');
            $table->string('phone_country')->default('MX');
            $table->date('birth_date');
            $table->string('gender')->nullable();
            $table->string('street');
            $table->string('number');
            $table->string('neighborhood');
            $table->string('state');
            $table->string('city');
            $table->string('zipcode');
            $table->unsignedInteger('total_cents')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoice_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tax_profile_id')->nullable()->constrained('tax_profiles')->nullOnDelete();
            $table->morphs('invoice_requestable', 'invoice_requestable_index');
            $table->string('name');
            $table->string('rfc');
            $table->string('zipcode');
            $table->string('tax_regime');
            $table->string('cfdi_use');
            $table->string('fiscal_certificate');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoice_requests', 'laboratory_purchases', 'tax_profiles', 'customers', 'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
