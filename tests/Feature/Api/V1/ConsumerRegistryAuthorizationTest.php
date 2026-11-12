<?php

namespace Tests\Feature\Api\V1;

use App\Models\Administrator;
use App\Models\Permission;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerRegistryWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Database\Rc2IsolatedDatabase;
use Tests\TestCase;

final class ConsumerRegistryAuthorizationTest extends TestCase
{
    use Rc2IsolatedDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetRc2IsolatedState();
    }

    #[Test]
    public function only_an_administrator_with_the_dedicated_permission_can_manage_the_registry(): void
    {
        $operator = User::factory()->create();
        $administrator = Administrator::create(['user_id' => $operator->id]);
        $administrator->givePermissionTo(Permission::firstOrCreate(['name' => 'api-consumers.manage', 'guard_name' => 'web']));

        $this->assertTrue(Gate::forUser($operator)->allows('manageApiConsumerRegistry'));
        $consumer = app(ConsumerRegistryWriter::class)->register($operator, 'leo', ['memberships:read'], Carbon::now()->addHour());
        $this->assertFalse($consumer->enabled);
    }

    #[Test]
    public function administrator_without_the_dedicated_permission_is_rejected(): void
    {
        $operator = User::factory()->create();
        Administrator::create(['user_id' => $operator->id]);

        $this->assertFalse(Gate::forUser($operator)->allows('manageApiConsumerRegistry'));
        $this->expectException(AuthorizationException::class);
        app(ConsumerRegistryWriter::class)->register($operator, 'leo', ['memberships:read'], Carbon::now()->addHour());
    }

    #[Test]
    public function a_user_without_an_administrator_is_rejected(): void
    {
        $operator = User::factory()->create();

        $this->assertFalse(Gate::forUser($operator)->allows('manageApiConsumerRegistry'));
        $this->expectException(AuthorizationException::class);
        app(ConsumerRegistryWriter::class)->register($operator, 'leo', ['memberships:read'], Carbon::now()->addHour());
    }
}
