<?php

use App\Actions\EfevooPay\ChargeEfevooPaymentMethodAction;
use App\Actions\Laboratories\CreateGDAQuotationAction;
use App\Actions\Laboratories\CreatePatientAction;
use App\Actions\Laboratories\CreatePractitionerAction;
use App\Actions\Laboratories\FulfillLaboratoryCartOrderAction;
use App\Actions\Laboratories\OrderAction;
use App\Actions\Laboratory\HandleStatusUpdateAction;
use App\Actions\Odessa\ChargeOdessaAction;
use App\Actions\Odessa\CheckBalanceAction;
use App\Actions\Odessa\GetOdessaPrivateTokenAction;
use App\Actions\PayPal\CapturePayPalOrderAction;
use App\Actions\PayPal\CreatePayPalOrderAction;
use App\Actions\PayPal\FinalizeLaboratoryPayPalPaymentAction;
use App\Actions\Transactions\RefundTransactionAction;
use App\Contracts\EfevooPayGateway;
use App\Enums\GdaOrderStatus;
use App\Enums\LaboratoryBrand;
use App\Enums\MonitoringCartStatus;
use App\Enums\MonitoringCartType;
use App\Exceptions\GdaOrderResultUncertainException;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryCartItem;
use App\Models\LaboratoryNotification;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryTest;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\LaboratoryPurchaseCreated;
use App\Notifications\OdessaPaymentRefunded;
use App\Services\PayPalService;
use App\Services\Monitoring\SyncMonitoringCartService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

function gdaP0Customer(): Customer
{
    return medicalAttentionUser()->customer;
}

function gdaP0OdessaCustomer(): Customer
{
    return User::factory()
        ->withCompleteProfile()
        ->has(Customer::factory()->withOdessaAfiliateAccount())
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer.customerable'])
        ->customer;
}

function gdaP0CartItem(Customer $customer, LaboratoryBrand $brand, int $priceCents = 39900): LaboratoryCartItem
{
    $test = LaboratoryTest::factory()->create([
        'brand' => $brand->value,
        'requires_appointment' => false,
        'public_price_cents' => $priceCents,
        'famedic_price_cents' => $priceCents,
        'gda_id' => 'LAB-'.$priceCents,
    ]);

    return LaboratoryCartItem::factory()->create([
        'customer_id' => $customer->id,
        'laboratory_test_id' => $test->id,
    ]);
}

function gdaP0Address(Customer $customer): Address
{
    return Address::factory()->create(['customer_id' => $customer->id]);
}

function gdaP0Contact(Customer $customer): Contact
{
    return Contact::factory()->create(['customer_id' => $customer->id]);
}

function gdaP0Transaction(int $amountCents = 39900, string $method = 'efevoopay'): Transaction
{
    return Transaction::factory()->create([
        'transaction_amount_cents' => $amountCents,
        'payment_method' => $method,
        'payment_status' => $method === 'paypal' ? 'pending' : 'completed',
        'gateway_status' => $method === 'paypal' ? 'CREATED' : 'completed',
        'reference_id' => fake()->uuid(),
    ]);
}

function gdaP0Cart(Customer $customer, int $amountCents = 39900): Cart
{
    return Cart::query()->create([
        'user_id' => $customer->user_id,
        'type' => MonitoringCartType::Lab,
        'status' => MonitoringCartStatus::Active,
        'total' => $amountCents / 100,
    ]);
}

function gdaP0SyncedCart(Customer $customer, LaboratoryBrand $brand): Cart
{
    app(SyncMonitoringCartService::class)->syncLaboratory($customer);

    return app(SyncMonitoringCartService::class)->activeLaboratoryCart($customer, $brand);
}

function gdaP0Purchase(Customer $customer, LaboratoryBrand $brand, GdaOrderStatus $status): LaboratoryPurchase
{
    return $customer->laboratoryPurchases()->create([
        'gda_order_id' => '0',
        'gda_status' => $status,
        'brand' => $brand,
        'name' => 'Paciente',
        'paternal_lastname' => 'Prueba',
        'maternal_lastname' => 'GDA',
        'phone' => '8180000000',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 39900,
    ]);
}

function gdaP0PurchaseForCart(Customer $customer, LaboratoryBrand $brand, Cart $cart, GdaOrderStatus $status): LaboratoryPurchase
{
    $purchase = gdaP0Purchase($customer, $brand, $status);
    $purchase->update(['cart_id' => $cart->id]);

    return $purchase->fresh();
}

function gdaP0QuotationAction(): CreateGDAQuotationAction
{
    config([
        'app.env' => 'production',
        'services.gda.url' => 'https://gda.example.test/',
        'services.gda.brands.olab.brand_id' => 'OLAB',
        'services.gda.brands.olab.token' => 'secret-token',
        'services.gda.brands.olab.brand_agreement_id' => 'AGREEMENT',
    ]);

    $patient = Mockery::mock(CreatePatientAction::class);
    $patient->shouldReceive('__invoke')->andReturn(1001);

    $practitioner = Mockery::mock(CreatePractitionerAction::class);
    $practitioner->shouldReceive('__invoke')->andReturn(2002);

    return new CreateGDAQuotationAction($patient, $practitioner);
}

test('gda quotation confirms only when folio and consecutivo are present without rigid folio regex', function () {
    Http::fake([
        'gda.example.test/*' => Http::response([
            'id' => 'ABC-123-NO-RIGID',
            'infogda_consecutivo' => '25273571',
            'status' => 'completed',
        ], 200),
    ]);

    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    $item = gdaP0CartItem($customer, $brand);

    $result = gdaP0QuotationAction()(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        $brand->value,
        collect([$item->load('laboratoryTest')]),
        1234,
    );

    expect($result['id'])->toBe('ABC-123-NO-RIGID')
        ->and($result['infogda_consecutivo'])->toBe('25273571');
});

test('gda quotation treats http 200 without required identifiers as uncertain', function () {
    Http::fake([
        'gda.example.test/*' => Http::response([
            'id' => '',
            'status' => 'completed',
        ], 200),
    ]);

    $customer = gdaP0Customer();
    $item = gdaP0CartItem($customer, LaboratoryBrand::OLAB);

    gdaP0QuotationAction()(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        LaboratoryBrand::OLAB->value,
        collect([$item->load('laboratoryTest')]),
        1235,
    );
})->throws(GdaOrderResultUncertainException::class);

test('gda quotation treats malformed json as uncertain', function () {
    Http::fake([
        'gda.example.test/*' => Http::response('<html>maintenance</html>', 200),
    ]);

    $customer = gdaP0Customer();
    $item = gdaP0CartItem($customer, LaboratoryBrand::OLAB);

    gdaP0QuotationAction()(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        LaboratoryBrand::OLAB->value,
        collect([$item->load('laboratoryTest')]),
        1236,
    );
})->throws(GdaOrderResultUncertainException::class);

test('gda quotation treats connection failures as uncertain', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    $customer = gdaP0Customer();
    $item = gdaP0CartItem($customer, LaboratoryBrand::OLAB);

    gdaP0QuotationAction()(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        LaboratoryBrand::OLAB->value,
        collect([$item->load('laboratoryTest')]),
        1237,
    );
})->throws(GdaOrderResultUncertainException::class);

test('fulfillment persists uncertain purchase but does not complete cart or send completed signals', function () {
    Notification::fake();

    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand);
    $cart = gdaP0SyncedCart($customer, $brand);
    $transaction = gdaP0Transaction();

    $this->mock(CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andThrow(GdaOrderResultUncertainException::forResponse('gda_missing_required_identifiers', 1, 200));
    });

    $items = $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get();

    try {
        app(FulfillLaboratoryCartOrderAction::class)(
            $customer,
            $brand,
            gdaP0Address($customer),
            gdaP0Contact($customer),
            $transaction,
            null,
            $items,
            $brand->value,
            null,
            null,
            null,
            $cart,
        );
        $this->fail('Expected uncertain GDA exception.');
    } catch (GdaOrderResultUncertainException $e) {
        $purchase = $e->purchase();
    }

    expect($purchase)->toBeInstanceOf(LaboratoryPurchase::class)
        ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Uncertain)
        ->and($purchase->transactions()->whereKey($transaction->id)->exists())->toBeTrue()
        ->and($cart->fresh()->status)->toBe(MonitoringCartStatus::Active)
        ->and($customer->laboratoryCartItems()->ofBrand($brand)->count())->toBe(1);

    Notification::assertNothingSent();
    Notification::assertNotSentTo($customer->user, LaboratoryPurchaseCreated::class);
});

test('successful gda confirmation marks purchase confirmed and completes cart', function () {
    Notification::fake();

    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand);
    $cart = gdaP0SyncedCart($customer, $brand);
    $transaction = gdaP0Transaction();

    $this->mock(CreateGDAQuotationAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')
            ->once()
            ->andReturn([
                'id' => 'GZ0L000671',
                'infogda_consecutivo' => 25273571,
            ]);
    });

    $purchase = app(FulfillLaboratoryCartOrderAction::class)(
        $customer,
        $brand,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        $transaction,
        null,
        $customer->laboratoryCartItems()->ofBrand($brand)->with('laboratoryTest')->get(),
        $brand->value,
        null,
        null,
        null,
        $cart,
    );

    expect($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Confirmed)
        ->and($purchase->fresh()->gda_order_id)->toBe('GZ0L000671')
        ->and($purchase->fresh()->gda_consecutivo)->toBe(25273571)
        ->and($cart->fresh()->status)->toBe(MonitoringCartStatus::Completed);
});

test('order action blocks second efevoopay charge when cart already has captured uncertain gda purchase', function () {
    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);
    $cart = gdaP0SyncedCart($customer, $brand);
    $purchase = gdaP0PurchaseForCart($customer, $brand, $cart, GdaOrderStatus::Uncertain);
    $transaction = gdaP0Transaction(39900, 'efevoopay');
    $transaction->update([
        'payment_status' => 'completed',
        'gateway_status' => 'completed',
        'gateway_transaction_id' => 'EFV-CAPTURED-1',
    ]);
    $purchase->transactions()->attach($transaction);

    $gateway = Mockery::mock(EfevooPayGateway::class);
    $gateway->shouldReceive('chargeCard')->never();
    $this->app->instance(EfevooPayGateway::class, $gateway);

    $result = app(OrderAction::class)(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        '1',
        $brand,
        39900,
    );

    expect($result->is($purchase))->toBeTrue()
        ->and(Transaction::query()->count())->toBe(1)
        ->and(LaboratoryPurchase::query()->count())->toBe(1)
        ->and($cart->fresh()->status)->toBe(MonitoringCartStatus::Active)
        ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Uncertain);
});

test('successful odessa charge is persisted as completed gateway transaction', function () {
    config(['services.odessa.url' => 'https://odessa.example.test/']);

    Http::fake([
        'https://odessa.example.test/applyCharge' => Http::response([
            'response' => [
                'errorCode' => 0,
                'transactionOds' => 'ODS-TXN-123',
            ],
        ], 200),
    ]);

    $customer = gdaP0OdessaCustomer();

    $tokenAction = Mockery::mock(GetOdessaPrivateTokenAction::class);
    $tokenAction->shouldReceive('__invoke')
        ->once()
        ->with($customer->customerable)
        ->andReturn('private-token');

    $checkBalance = Mockery::mock(CheckBalanceAction::class);
    $checkBalance->shouldReceive('__invoke')
        ->once()
        ->with('private-token', 39900)
        ->andReturnTrue();

    $transaction = (new ChargeOdessaAction($tokenAction, $checkBalance))(
        $customer->customerable,
        39900,
    );

    expect($transaction->payment_method)->toBe('odessa')
        ->and($transaction->reference_id)->toBe('ODS-TXN-123')
        ->and($transaction->payment_status)->toBe('completed')
        ->and($transaction->gateway)->toBe('odessa')
        ->and($transaction->gateway_status)->toBe('completed')
        ->and($transaction->gateway_transaction_id)->toBe('ODS-TXN-123')
        ->and($transaction->gateway_processed_at)->not->toBeNull();
});

test('order action blocks second odessa charge when cart already has captured uncertain gda purchase', function () {
    $customer = gdaP0OdessaCustomer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);
    $cart = gdaP0SyncedCart($customer, $brand);
    $purchase = gdaP0PurchaseForCart($customer, $brand, $cart, GdaOrderStatus::Uncertain);
    $transaction = gdaP0Transaction(39900, 'odessa');
    $transaction->update([
        'reference_id' => 'ODS-TXN-456',
        'payment_status' => 'completed',
        'gateway' => 'odessa',
        'gateway_status' => 'completed',
        'gateway_transaction_id' => 'ODS-TXN-456',
        'gateway_processed_at' => now(),
    ]);
    $purchase->transactions()->attach($transaction);

    $this->mock(ChargeOdessaAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->never();
    });

    $result = app(OrderAction::class)(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        'odessa',
        $brand,
        39900,
    );

    $transaction->refresh();

    expect($result->is($purchase))->toBeTrue()
        ->and(Transaction::query()->count())->toBe(1)
        ->and(LaboratoryPurchase::query()->count())->toBe(1)
        ->and($cart->fresh()->status)->toBe(MonitoringCartStatus::Active)
        ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Uncertain)
        ->and($transaction->payment_method)->toBe('odessa')
        ->and($transaction->payment_status)->toBe('completed')
        ->and($transaction->gateway_status)->toBe('completed')
        ->and($transaction->gateway_transaction_id)->toBe('ODS-TXN-456');
});

test('odessa refund remains administrative for completed odessa transaction', function () {
    Notification::fake();
    config(['services.odessa.refund_report_emails' => ['ops@example.test']]);

    $customer = gdaP0OdessaCustomer();
    $purchase = gdaP0Purchase($customer, LaboratoryBrand::OLAB, GdaOrderStatus::Confirmed);
    $transaction = Transaction::factory()->create([
        'transaction_amount_cents' => 39900,
        'payment_method' => 'odessa',
        'reference_id' => 'ODS-TXN-789',
        'payment_status' => 'completed',
        'gateway' => 'odessa',
        'gateway_status' => 'completed',
        'gateway_transaction_id' => 'ODS-TXN-789',
        'gateway_processed_at' => now(),
    ]);
    $purchase->transactions()->attach($transaction);

    $result = app(RefundTransactionAction::class)($transaction);

    $refunded = Transaction::withTrashed()->find($transaction->id);

    expect($result)->toBeTrue()
        ->and($refunded->refunded_at)->not->toBeNull()
        ->and($refunded->gateway_status)->toBe('refunded')
        ->and($refunded->trashed())->toBeTrue();

    Notification::assertSentOnDemand(OdessaPaymentRefunded::class);
});

test('paypal create order blocks new paypal order when cart already has captured uncertain gda purchase', function () {
    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);
    $cart = gdaP0SyncedCart($customer, $brand);
    $purchase = gdaP0PurchaseForCart($customer, $brand, $cart, GdaOrderStatus::Uncertain);
    $transaction = gdaP0Transaction(39900, 'paypal');
    $transaction->update([
        'payment_status' => 'captured',
        'gateway_status' => 'COMPLETED',
        'provider_transaction_id' => 'PAYPAL-CAPTURED-1',
    ]);
    $purchase->transactions()->attach($transaction);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('createOrder')->never();
    $this->app->instance(PayPalService::class, $paypal);

    try {
        app(CreatePayPalOrderAction::class)(
            $customer,
            gdaP0Address($customer),
            gdaP0Contact($customer),
            $brand,
            39900,
        );
        $this->fail('Expected already received payment exception.');
    } catch (\App\Exceptions\LaboratoryPaymentAlreadyReceivedException $e) {
        expect($e->purchase()->is($purchase))->toBeTrue()
            ->and(Transaction::query()->count())->toBe(1)
            ->and(LaboratoryPurchase::query()->count())->toBe(1)
            ->and($cart->fresh()->status)->toBe(MonitoringCartStatus::Active)
            ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Uncertain);
    }
});

test('paypal capture blocks second paypal order when cart already has captured uncertain gda purchase', function () {
    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);
    $cart = gdaP0SyncedCart($customer, $brand);
    $purchase = gdaP0PurchaseForCart($customer, $brand, $cart, GdaOrderStatus::Uncertain);

    $capturedTransaction = gdaP0Transaction(39900, 'paypal');
    $capturedTransaction->update([
        'payment_status' => 'captured',
        'gateway_status' => 'COMPLETED',
        'provider_transaction_id' => 'PAYPAL-CAPTURED-2',
    ]);
    $purchase->transactions()->attach($capturedTransaction);

    $secondTransaction = gdaP0Transaction(39900, 'paypal');
    $secondTransaction->update([
        'reference_id' => 'PAYPAL-SECOND-ORDER',
        'provider_order_id' => 'PAYPAL-SECOND-ORDER',
        'payment_status' => 'pending',
        'details' => [
            'customer_id' => $customer->id,
            'laboratory_brand' => $brand->value,
            'address_id' => gdaP0Address($customer)->id,
            'contact_id' => gdaP0Contact($customer)->id,
            'total_cents' => 39900,
        ],
    ]);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('captureOrder')->never();
    $this->app->instance(PayPalService::class, $paypal);

    $result = app(CapturePayPalOrderAction::class)('PAYPAL-SECOND-ORDER', $customer);

    expect($result['status'])->toBe('gda_uncertain')
        ->and($result['purchase']->is($purchase))->toBeTrue()
        ->and($secondTransaction->fresh()->payment_status)->toBe('pending');
});

test('order action does not refund when fulfillment reports uncertain gda after payment', function () {
    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);

    $transaction = gdaP0Transaction();
    $purchase = gdaP0Purchase($customer, $brand, GdaOrderStatus::Uncertain);
    $exception = GdaOrderResultUncertainException::forResponse('gda_connection_error', $purchase->id)
        ->withPurchase($purchase);

    $this->mock(ChargeEfevooPaymentMethodAction::class, function ($mock) use ($transaction) {
        $mock->shouldReceive('__invoke')->once()->andReturn($transaction);
    });

    $this->mock(FulfillLaboratoryCartOrderAction::class, function ($mock) use ($exception) {
        $mock->shouldReceive('__invoke')->once()->andThrow($exception);
    });

    $this->mock(RefundTransactionAction::class, function ($mock) {
        $mock->shouldReceive('__invoke')->never();
    });

    $result = app(OrderAction::class)(
        $customer,
        gdaP0Address($customer),
        gdaP0Contact($customer),
        'card',
        $brand,
        39900,
    );

    expect($result->is($purchase))->toBeTrue();
});

test('paypal finalizer does not refund or mark transaction failed when gda is uncertain', function () {
    $customer = gdaP0Customer();
    $brand = LaboratoryBrand::OLAB;
    gdaP0CartItem($customer, $brand, 39900);
    $address = gdaP0Address($customer);
    $contact = gdaP0Contact($customer);

    $transaction = gdaP0Transaction(39900, 'paypal');
    $transaction->update([
        'details' => [
            'customer_id' => $customer->id,
            'laboratory_brand' => $brand->value,
            'address_id' => $address->id,
            'contact_id' => $contact->id,
            'total_cents' => 39900,
        ],
    ]);

    $purchase = gdaP0Purchase($customer, $brand, GdaOrderStatus::Uncertain);
    $exception = GdaOrderResultUncertainException::forResponse('gda_timeout', $purchase->id)
        ->withPurchase($purchase);

    $paypal = Mockery::mock(PayPalService::class);
    $paypal->shouldReceive('extractCaptureInfo')->once()->andReturn([
        'capture_id' => 'CAPTURE-123',
        'status' => 'COMPLETED',
    ]);
    $paypal->shouldReceive('refund')->never();
    $this->app->instance(PayPalService::class, $paypal);

    $this->mock(FulfillLaboratoryCartOrderAction::class, function ($mock) use ($exception) {
        $mock->shouldReceive('__invoke')->once()->andThrow($exception);
    });

    $result = app(FinalizeLaboratoryPayPalPaymentAction::class)($transaction, [
        'purchase_units' => [[
            'payments' => [
                'captures' => [[
                    'id' => 'CAPTURE-123',
                    'status' => 'COMPLETED',
                ]],
            ],
        ]],
    ]);

    expect($result->is($purchase))->toBeTrue()
        ->and($transaction->fresh()->payment_status)->toBe('captured')
        ->and($transaction->fresh()->gateway_status)->toBe('COMPLETED');
});

test('status webhooks do not overwrite purchase gda order status enum values', function () {
    $customer = gdaP0Customer();
    $purchase = gdaP0Purchase($customer, LaboratoryBrand::OLAB, GdaOrderStatus::Confirmed);
    $notification = LaboratoryNotification::query()->create([
        'laboratory_purchase_id' => $purchase->id,
        'gda_order_id' => 'GDA-STATUS-1',
        'notification_type' => 'status_update',
        'status' => LaboratoryNotification::STATUS_RECEIVED,
        'payload' => [],
    ]);

    app(HandleStatusUpdateAction::class)->execute($notification, [
        'id' => 'GDA-STATUS-1',
        'status' => 'completed',
        'GDA_menssage' => [
            'acuse' => 'acuse-status-1',
        ],
    ], [
        'purchase_id' => $purchase->id,
    ]);

    expect($notification->fresh()->gda_status)->toBe('completed')
        ->and($purchase->fresh()->gda_status)->toBe(GdaOrderStatus::Confirmed)
        ->and($purchase->fresh()->gda_acuse)->toBe('acuse-status-1');
});

test('gda status migration backfills confirmed only for legacy purchases with folio and consecutivo evidence', function () {
    if (Schema::hasColumn('laboratory_purchases', 'gda_status')) {
        Schema::table('laboratory_purchases', function ($table) {
            try {
                $table->dropIndex('laboratory_purchases_gda_status_idx');
            } catch (Throwable) {
            }
            $table->dropColumn('gda_status');
        });
    }

    $customer = gdaP0Customer();
    $base = [
        'brand' => LaboratoryBrand::OLAB->value,
        'name' => 'Legacy',
        'paternal_lastname' => 'Backfill',
        'maternal_lastname' => 'Test',
        'phone' => '8180000000',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => 1,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo León',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'total_cents' => 39900,
        'customer_id' => $customer->id,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('laboratory_purchases')->insert([
        $base + ['id' => 900001, 'gda_order_id' => 'GZ0L000671', 'gda_consecutivo' => 25273571],
        $base + ['id' => 900002, 'gda_order_id' => '', 'gda_consecutivo' => null],
        $base + ['id' => 900003, 'gda_order_id' => '0', 'gda_consecutivo' => 25273572],
        $base + ['id' => 900004, 'gda_order_id' => 'HD0L001631', 'gda_consecutivo' => null],
    ]);

    $migration = require database_path('migrations/2026_09_22_000000_add_gda_status_to_laboratory_purchases_table.php');
    $migration->up();

    expect(DB::table('laboratory_purchases')->where('id', 900001)->value('gda_status'))->toBe(GdaOrderStatus::Confirmed->value)
        ->and(DB::table('laboratory_purchases')->where('id', 900002)->value('gda_status'))->toBeNull()
        ->and(DB::table('laboratory_purchases')->where('id', 900003)->value('gda_status'))->toBeNull()
        ->and(DB::table('laboratory_purchases')->where('id', 900004)->value('gda_status'))->toBeNull();

    $newPurchase = gdaP0Purchase($customer, LaboratoryBrand::OLAB, GdaOrderStatus::Pending);

    expect($newPurchase->fresh()->gda_status)->toBe(GdaOrderStatus::Pending);
});
