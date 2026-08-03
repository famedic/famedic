<?php

namespace Tests\Feature\Invoices;

use App\Actions\CreateInvoiceAction;
use App\Http\Requests\Admin\LaboratoryPurchases\StoreInvoiceRequest;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\LaboratoryPurchase;
use App\Models\User;
use App\Notifications\PurchaseInvoiceUploaded;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Esquema aislado: evita migraciones históricas incompatibles con SQLite.
 */
class InvoiceXmlSupportIsolatedTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        $this->storageRoot = sys_get_temp_dir().'/famedic-invoice-xml-'.getmypid().'-'.uniqid('', true);
        mkdir($this->storageRoot, 0777, true);

        config([
            'filesystems.default' => 'local',
            'filesystems.disks.local.root' => $this->storageRoot,
            'filesystems.disks.local.throw' => true,
        ]);
        Storage::forgetDisk('local');

        Notification::fake();
        $this->bootstrapSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        if (! empty($this->storageRoot) && is_dir($this->storageRoot)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->storageRoot, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }

            @rmdir($this->storageRoot);
        }

        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function create_invoice_stores_pdf_only(): void
    {
        $purchase = $this->makePurchase();

        $invoice = app(CreateInvoiceAction::class)(
            $purchase,
            UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            null,
        );

        $this->assertNotNull($invoice->invoice);
        $this->assertNull($invoice->invoice_xml);
        $this->assertTrue(Storage::exists($invoice->invoice));
        Notification::assertSentTo($purchase->customer->user, PurchaseInvoiceUploaded::class);
    }

    #[Test]
    public function create_invoice_stores_pdf_and_xml_together(): void
    {
        $purchase = $this->makePurchase();

        $invoice = app(CreateInvoiceAction::class)(
            $purchase,
            UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->create('factura.xml', 20, 'application/xml'),
        );

        $this->assertNotNull($invoice->invoice);
        $this->assertNotNull($invoice->invoice_xml);
        $this->assertTrue(Storage::exists($invoice->invoice));
        $this->assertTrue(Storage::exists($invoice->invoice_xml));
    }

    #[Test]
    public function adding_xml_only_keeps_existing_pdf(): void
    {
        $purchase = $this->makePurchase();
        $existingPdf = 'invoices/existing.pdf';
        Storage::put($existingPdf, 'pdf');

        $purchase->invoice()->create([
            'invoice' => $existingPdf,
        ]);

        $invoice = app(CreateInvoiceAction::class)(
            $purchase->fresh(['invoice', 'customer.user']),
            null,
            UploadedFile::fake()->create('factura.xml', 20, 'application/xml'),
        );

        $this->assertSame($existingPdf, $invoice->invoice);
        $this->assertNotNull($invoice->invoice_xml);
        $this->assertTrue(Storage::exists($existingPdf));
        $this->assertTrue(Storage::exists($invoice->invoice_xml));
    }

    #[Test]
    public function replacing_pdf_does_not_modify_existing_xml(): void
    {
        $purchase = $this->makePurchase();
        $existingPdf = 'invoices/old.pdf';
        $existingXml = 'invoices/old.xml';
        Storage::put($existingPdf, 'pdf');
        Storage::put($existingXml, 'xml');

        $purchase->invoice()->create([
            'invoice' => $existingPdf,
            'invoice_xml' => $existingXml,
        ]);

        $invoice = app(CreateInvoiceAction::class)(
            $purchase->fresh(['invoice', 'customer.user']),
            UploadedFile::fake()->create('nuevo.pdf', 100, 'application/pdf'),
            null,
        );

        $this->assertNotSame($existingPdf, $invoice->invoice);
        $this->assertSame($existingXml, $invoice->invoice_xml);
        $this->assertTrue(Storage::exists($invoice->invoice));
        $this->assertTrue(Storage::exists($existingXml));
    }

    #[Test]
    public function replacing_xml_does_not_modify_existing_pdf(): void
    {
        $purchase = $this->makePurchase();
        $existingPdf = 'invoices/keep.pdf';
        $existingXml = 'invoices/replace.xml';
        Storage::put($existingPdf, 'pdf');
        Storage::put($existingXml, 'xml');

        $purchase->invoice()->create([
            'invoice' => $existingPdf,
            'invoice_xml' => $existingXml,
        ]);

        $invoice = app(CreateInvoiceAction::class)(
            $purchase->fresh(['invoice', 'customer.user']),
            null,
            UploadedFile::fake()->create('nuevo.xml', 20, 'application/xml'),
        );

        $this->assertSame($existingPdf, $invoice->invoice);
        $this->assertNotSame($existingXml, $invoice->invoice_xml);
        $this->assertTrue(Storage::exists($existingPdf));
        $this->assertTrue(Storage::exists($invoice->invoice_xml));
    }

    #[Test]
    public function first_upload_requires_pdf(): void
    {
        $purchase = $this->makePurchase();

        $validator = $this->makeStoreInvoiceValidator($purchase, [
            'invoice_xml' => UploadedFile::fake()->create('factura.xml', 20, 'application/xml'),
        ]);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('invoice', $validator->errors()->toArray());
    }

    #[Test]
    public function update_requires_at_least_one_file(): void
    {
        $purchase = $this->makePurchase();
        $purchase->invoice()->create(['invoice' => 'invoices/a.pdf']);

        $validator = $this->makeStoreInvoiceValidator($purchase->fresh(['invoice']), []);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('invoice', $validator->errors()->toArray());
    }

    #[Test]
    public function rejects_non_xml_extension(): void
    {
        $validator = Validator::make(
            [
                'invoice' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
                'invoice_xml' => UploadedFile::fake()->create('factura.txt', 10, 'text/plain'),
            ],
            $this->fileRules(),
            (new StoreInvoiceRequest)->messages(),
            (new StoreInvoiceRequest)->attributes(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('invoice_xml', $validator->errors()->toArray());
    }

    #[Test]
    public function accepts_uppercase_xml_extension(): void
    {
        $validator = Validator::make(
            [
                'invoice' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
                'invoice_xml' => UploadedFile::fake()->create('FACTURA.XML', 20, 'application/xml'),
            ],
            $this->fileRules(),
            (new StoreInvoiceRequest)->messages(),
            (new StoreInvoiceRequest)->attributes(),
        );

        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
    }

    #[Test]
    public function rejects_xml_larger_than_5mb(): void
    {
        $validator = Validator::make(
            [
                'invoice' => UploadedFile::fake()->create('factura.pdf', 100, 'application/pdf'),
                'invoice_xml' => UploadedFile::fake()->create('factura.xml', 5121, 'application/xml'),
            ],
            $this->fileRules(),
            (new StoreInvoiceRequest)->messages(),
            (new StoreInvoiceRequest)->attributes(),
        );

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('invoice_xml', $validator->errors()->toArray());
    }

    #[Test]
    public function historical_pdf_only_records_remain_compatible(): void
    {
        $purchase = $this->makePurchase();

        $invoice = Invoice::create([
            'invoiceable_type' => LaboratoryPurchase::class,
            'invoiceable_id' => $purchase->id,
            'invoice' => 'invoices/legacy.pdf',
            'invoice_xml' => null,
        ]);

        $this->assertFalse($invoice->fresh()->has_invoice_xml);
        $this->assertSame('invoices/legacy.pdf', $invoice->invoice);
    }

    private function fileRules(): array
    {
        return [
            'invoice' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'invoice_xml' => ['nullable', 'file', 'extensions:xml', 'max:5120'],
        ];
    }

    private function makeStoreInvoiceValidator(LaboratoryPurchase $purchase, array $files)
    {
        $request = StoreInvoiceRequest::create(
            '/admin/laboratory-purchases/'.$purchase->id.'/invoice',
            'POST',
            [],
            [],
            $files,
        );
        $request->setContainer(app());
        $request->setRedirector(app('redirect'));
        $request->setUserResolver(fn () => $purchase->customer->user);
        $request->laboratory_purchase = $purchase;

        // Validar con las rules() del FormRequest. No invocar withValidator():
        // ese hook solo existe si se define en la clase y lo ejecuta Laravel
        // durante el ciclo normal de FormRequest, no desde tests.
        $validator = Validator::make(
            array_merge($request->all(), $files),
            $request->rules(),
            $request->messages(),
            $request->attributes(),
        );

        $validator->passes();

        return $validator;
    }

    private function makePurchase(): LaboratoryPurchase
    {
        $user = User::query()->create([
            'name' => 'Paciente',
            'email' => 'paciente-'.uniqid('', true).'@test.local',
            'password' => bcrypt('password'),
        ]);

        $customer = Customer::query()->create([
            'user_id' => $user->id,
            'customerable_type' => 'App\\Models\\RegularAccount',
            'customerable_id' => 1,
        ]);

        return LaboratoryPurchase::query()->create([
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
    }

    private function bootstrapSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'invoices',
            'laboratory_purchases',
            'customers',
            'users',
            'notifications',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
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

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->morphs('invoiceable');
            $table->string('invoice');
            $table->string('invoice_xml')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    private function dropSchema(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'notifications',
            'invoices',
            'laboratory_purchases',
            'customers',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
}
