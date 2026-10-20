<?php

namespace Tests\Feature\InvoiceRequests;

use App\Enums\InvoiceRequestWorkflowStatus;
use App\Models\Customer;
use App\Models\InvoiceRequest;
use App\Models\LaboratoryPurchase;
use App\Models\TaxProfile;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class InvoiceRequestWorkflowPhase3Test extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureDocumentationIsAccepted::class,
            \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
            'password.confirm',
        ]);

        $this->bootstrapSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function paciente_con_solicitud_awaiting_ve_workflow_status_en_payload(): void
    {
        [$user, $purchase, $request] = $this->seedPurchaseWithInvoiceRequest(
            InvoiceRequestWorkflowStatus::AwaitingSampleCollection,
        );

        $this->actingAs($user)
            ->get(route('laboratory-purchases.show', ['laboratory_purchase' => $purchase]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('laboratoryPurchase.invoice_request.id', $request->id)
                ->where(
                    'laboratoryPurchase.invoice_request.workflow_status',
                    InvoiceRequestWorkflowStatus::AwaitingSampleCollection->value
                )
                ->where('laboratoryPurchase.invoice_request.formatted_created_at', fn ($value) => filled($value))
                ->whereNull('laboratoryPurchase.invoice_request.activated_by'));
    }

    #[Test]
    public function paciente_con_solicitud_submitted_ve_workflow_status_en_payload(): void
    {
        [$user, $purchase, $request] = $this->seedPurchaseWithInvoiceRequest(
            InvoiceRequestWorkflowStatus::SubmittedToBilling,
            [
                'submitted_to_billing_at' => now(),
                'activated_by' => 'sample_webhook',
            ],
        );

        $this->actingAs($user)
            ->get(route('laboratory-purchases.show', ['laboratory_purchase' => $purchase]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('laboratoryPurchase.invoice_request.id', $request->id)
                ->where(
                    'laboratoryPurchase.invoice_request.workflow_status',
                    InvoiceRequestWorkflowStatus::SubmittedToBilling->value
                )
                ->where(
                    'laboratoryPurchase.invoice_request.formatted_submitted_to_billing_at',
                    fn ($value) => filled($value)
                ));
    }

    #[Test]
    public function paciente_con_factura_mantiene_invoice_en_payload(): void
    {
        [$user, $purchase] = $this->seedPurchaseWithInvoiceRequest(
            InvoiceRequestWorkflowStatus::SubmittedToBilling,
            [
                'submitted_to_billing_at' => now(),
                'with_invoice' => true,
            ],
        );

        $this->actingAs($user)
            ->get(route('laboratory-purchases.show', ['laboratory_purchase' => $purchase]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('laboratoryPurchase.invoice.id')
                ->where('laboratoryPurchase.invoice.has_invoice_xml', true));
    }

    /**
     * @param  array<string, mixed>  $requestOverrides
     * @return array{0: User, 1: LaboratoryPurchase, 2: InvoiceRequest}
     */
    private function seedPurchaseWithInvoiceRequest(
        InvoiceRequestWorkflowStatus $workflowStatus,
        array $requestOverrides = [],
    ): array {
        $user = User::query()->create([
            'name' => 'Paciente',
            'email' => 'patient-'.uniqid('', true).'@test.local',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'customerable_type' => 'App\\Models\\RegularAccount',
            'customerable_id' => 1,
        ]);

        $profile = $customer->taxProfiles()->create([
            'name' => 'Persona Fiscal',
            'rfc' => 'MEBE931209BI2',
            'zipcode' => '64000',
            'tax_regime' => '612',
            'cfdi_use' => 'G03',
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'customer_id' => $customer->id,
            'brand' => 'olab',
            'gda_order_id' => (string) random_int(100000, 999999),
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => 1,
            'street' => 'Calle',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 10000,
        ]);

        $request = $purchase->invoiceRequest()->create([
            'tax_profile_id' => $profile->id,
            'name' => $profile->name,
            'rfc' => $profile->rfc,
            'zipcode' => $profile->zipcode,
            'tax_regime' => $profile->tax_regime,
            'cfdi_use' => $profile->cfdi_use,
            'fiscal_certificate' => 'invoice-requests/test.pdf',
            'workflow_status' => $workflowStatus->value,
            'submitted_to_billing_at' => $requestOverrides['submitted_to_billing_at'] ?? null,
            'activated_by' => $requestOverrides['activated_by'] ?? null,
        ]);

        if (! empty($requestOverrides['with_invoice'])) {
            $purchase->invoice()->create([
                'invoice' => 'invoices/test.pdf',
                'invoice_xml' => 'invoices/test.xml',
                'completed_at' => now(),
            ]);
        }

        return [$user, $purchase, $request];
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoices',
            'invoice_requests',
            'tax_profiles',
            'laboratory_purchases',
            'customers',
            'users',
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
            $table->string('rfc')->nullable();
            $table->string('zipcode')->nullable();
            $table->string('tax_regime')->nullable();
            $table->string('cfdi_use')->nullable();
            $table->string('fiscal_certificate')->nullable();
            $table->string('razon_social')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('laboratory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained();
            $table->string('brand')->default('olab');
            $table->string('gda_order_id')->nullable();
            $table->string('gda_status')->default('pending');
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
            $table->string('workflow_status', 50)
                ->default(InvoiceRequestWorkflowStatus::AwaitingSampleCollection->value);
            $table->timestamp('submitted_to_billing_at')->nullable();
            $table->timestamp('sample_completed_at')->nullable();
            $table->timestamp('billing_team_notified_at')->nullable();
            $table->string('activated_by', 50)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('invoiceable');
            $table->string('invoice')->nullable();
            $table->string('invoice_xml')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach ([
            'invoices',
            'invoice_requests',
            'tax_profiles',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
