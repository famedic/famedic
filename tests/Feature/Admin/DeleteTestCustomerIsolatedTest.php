<?php

namespace Tests\Feature\Admin;

use App\Actions\Admin\Customers\DeleteNonProductionCustomerAction;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Enums\MedicalSubscriptionType;
use App\Models\Administrator;
use App\Models\Contact;
use App\Models\Customer;
use App\Models\LaboratoryPurchase;
use App\Models\MedicalAttentionSubscription;
use App\Models\MurguiaSyncLog;
use App\Models\Permission;
use App\Models\RegularAccount;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

require_once __DIR__.'/deleteTestCustomerIsolatedSchema.php';

class DeleteTestCustomerIsolatedTest extends TestCase
{
    protected function setUp(): void
    {
        RefreshDatabaseState::$migrated = true;

        parent::setUp();

        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        bootstrapIsolatedDeleteTestCustomerSchema();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Queue::fake();

        $this->withoutMiddleware([
            \App\Http\Middleware\EnsureUserHasAdminAccount::class,
        ]);
    }

    protected function tearDown(): void
    {
        tearDownIsolatedDeleteTestCustomerSchema();

        parent::tearDown();
    }

    private function makeCustomerAdminUser(): User
    {
        $permission = Permission::create([
            'name' => 'customers.manage',
            'guard_name' => 'web',
        ]);

        $user = User::factory()->create();
        $administrator = Administrator::factory()->for($user)->create();
        $administrator->givePermissionTo($permission);

        return $user->fresh()->load('administrator');
    }

    /** @return array{user: User, customer: Customer, contact: Contact, subscription: MedicalAttentionSubscription, purchase: LaboratoryPurchase} */
    private function makeDeletableTestCustomer(): array
    {
        $user = User::factory()
            ->withCompleteProfile()
            ->withRegularCustomer()
            ->create();

        $customer = $user->customer;
        $contact = Contact::withoutEvents(fn () => Contact::create([
            'customer_id' => $customer->id,
            'name' => 'Contacto',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
        ]));

        $subscription = MedicalAttentionSubscription::create([
            'customer_id' => $customer->id,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'price_cents' => 10_000,
            'type' => MedicalSubscriptionType::REGULAR,
        ]);

        MurguiaSyncLog::create([
            'customer_id' => $customer->id,
            'action' => 'validacion',
            'status' => 'success',
            'request_payload' => ['test' => true],
            'response_payload' => ['ok' => true],
        ]);

        $purchase = LaboratoryPurchase::create([
            'brand' => LaboratoryBrand::OLAB->value,
            'gda_order_id' => '123456',
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Test',
            'phone' => '8112345678',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-01',
            'gender' => Gender::MALE->value,
            'street' => 'Calle Test',
            'number' => '100',
            'neighborhood' => 'Centro',
            'state' => 'NL',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 50_000,
            'customer_id' => $customer->id,
        ]);

        $transaction = Transaction::create([
            'transaction_amount_cents' => 50_000,
            'payment_method' => 'efevoopay',
            'gateway' => 'efevoopay',
            'details' => ['customer_id' => $customer->id],
        ]);
        $purchase->transactions()->attach($transaction->id);

        return compact('user', 'customer', 'contact', 'subscription', 'purchase');
    }

    #[Test]
    public function en_production_la_accion_y_la_ruta_estan_bloqueadas(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['customer' => $customer] = $this->makeDeletableTestCustomer();

        $this->app['env'] = 'production';

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        try {
            app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);
        } finally {
            $this->assertNotNull(Customer::withTrashed()->find($customer->id));
        }
    }

    #[Test]
    public function en_ambiente_no_production_un_admin_autorizado_puede_eliminar_el_usuario_de_prueba(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['user' => $user, 'customer' => $customer] = $this->makeDeletableTestCustomer();

        app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);

        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->assertNull(User::query()->find($user->id));
    }

    #[Test]
    public function el_controlador_redirige_al_listado_con_mensaje_de_exito(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['customer' => $customer] = $this->makeDeletableTestCustomer();

        $request = \App\Http\Requests\Admin\Customers\DeleteTestCustomerRequest::create(
            route('admin.customers.delete-test-user', $customer),
            'DELETE'
        );
        $request->setUserResolver(fn () => $admin);
        $request->setRouteResolver(function () use ($customer) {
            $route = new \Illuminate\Routing\Route('DELETE', '/admin/customers/{customer}/delete-test-user', []);
            $route->bind(request());
            $route->setParameter('customer', $customer);

            return $route;
        });

        $response = app(\App\Http\Controllers\Admin\CustomerTestDeletionController::class)
            ->destroy($request, $customer, app(DeleteNonProductionCustomerAction::class));

        $this->assertTrue($response->isRedirect(route('admin.customers.index')));
        $this->assertSame(
            'Usuario de prueba eliminado correctamente.',
            $response->getSession()->get('success')
        );
    }

    #[Test]
    public function se_eliminan_customer_user_contactos_y_relaciones_principales(): void
    {
        $admin = $this->makeCustomerAdminUser();
        [
            'user' => $user,
            'customer' => $customer,
            'contact' => $contact,
            'subscription' => $subscription,
            'purchase' => $purchase,
        ] = $this->makeDeletableTestCustomer();

        $regularAccountId = $customer->customerable_id;

        app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);

        $this->assertNull(User::query()->find($user->id));
        $this->assertNull(Customer::withTrashed()->find($customer->id));
        $this->assertNull(Contact::withTrashed()->find($contact->id));
        $this->assertNull(MedicalAttentionSubscription::withTrashed()->find($subscription->id));
        $this->assertNull(LaboratoryPurchase::withTrashed()->find($purchase->id));
        $this->assertFalse(MurguiaSyncLog::query()->where('customer_id', $customer->id)->exists());
        $this->assertNull(RegularAccount::withTrashed()->find($regularAccountId));
        $this->assertSame(0, Transaction::withTrashed()->count());
    }

    #[Test]
    public function no_quedan_registros_huerfanos_en_tablas_clave(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['user' => $user, 'customer' => $customer] = $this->makeDeletableTestCustomer();

        app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);

        $this->assertFalse(Contact::withTrashed()->where('customer_id', $customer->id)->exists());
        $this->assertFalse(MedicalAttentionSubscription::withTrashed()->where('customer_id', $customer->id)->exists());
        $this->assertFalse(LaboratoryPurchase::withTrashed()->where('customer_id', $customer->id)->exists());
        $this->assertFalse(MurguiaSyncLog::query()->where('customer_id', $customer->id)->exists());
        $this->assertFalse(Customer::withTrashed()->where('user_id', $user->id)->exists());
    }

    #[Test]
    public function la_accion_no_falla_si_algunas_relaciones_opcionales_no_existen(): void
    {
        $admin = $this->makeCustomerAdminUser();

        $user = User::factory()->withRegularCustomer()->create();
        $customer = $user->customer;

        app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);

        $this->assertNull(User::query()->find($user->id));
        $this->assertNull(Customer::withTrashed()->find($customer->id));
    }

    #[Test]
    public function se_registra_auditoria_en_logs(): void
    {
        Log::spy();

        $admin = $this->makeCustomerAdminUser();
        ['user' => $user, 'customer' => $customer] = $this->makeDeletableTestCustomer();

        app(DeleteNonProductionCustomerAction::class)->execute($customer, $admin);

        Log::shouldHaveReceived('info')
            ->withArgs(function (string $message, array $context) use ($admin, $user, $customer) {
                return $message === 'DeleteNonProductionCustomerAction: completado'
                    && ($context['actor_user_id'] ?? null) === $admin->id
                    && ($context['user_id'] ?? null) === $user->id
                    && ($context['customer_id'] ?? null) === $customer->id
                    && isset($context['deleted_counts']);
            });
    }

    #[Test]
    public function can_delete_test_user_no_se_expone_en_production(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['customer' => $customer] = $this->makeDeletableTestCustomer();

        $this->app['env'] = 'production';

        $this->assertFalse($admin->can('deleteTestUser', $customer));
    }

    #[Test]
    public function can_delete_test_user_si_se_expone_en_testing_para_admin_autorizado(): void
    {
        $admin = $this->makeCustomerAdminUser();
        ['customer' => $customer] = $this->makeDeletableTestCustomer();

        $this->assertTrue($admin->can('deleteTestUser', $customer));
    }
}
