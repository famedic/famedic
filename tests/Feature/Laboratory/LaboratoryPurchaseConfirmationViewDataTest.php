<?php

namespace Tests\Feature\Laboratory;

use App\Actions\Laboratories\LaboratoryPurchaseConfirmationViewData;
use App\Enums\Gender;
use App\Enums\LaboratoryBrand;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryStore;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LaboratoryPurchaseConfirmationViewDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_includes_preferred_store_details_when_selected(): void
    {
        $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create([
            'documentation_accepted_at' => now(),
        ])->fresh('customer');

        $store = LaboratoryStore::query()->create([
            'name' => 'Pastora',
            'brand' => LaboratoryBrand::SWISSLAB,
            'state' => 'Nuevo Leon',
            'is_active' => true,
            'address' => 'Eloy Cavazos, Las Villas, Guadalupe, 67175',
            'phone' => '8180001234',
            'weekly_hours' => '7:00 a 15:00',
            'saturday_hours' => '8:00 a 12:00',
            'sunday_hours' => 'Cerrado',
            'google_maps_url' => 'https://maps.google.com/?q=pastora',
        ]);

        $purchase = LaboratoryPurchase::query()->create([
            'brand' => LaboratoryBrand::SWISSLAB,
            'gda_order_id' => 'GDA-PREF-001',
            'name' => 'Paciente',
            'paternal_lastname' => 'Prueba',
            'maternal_lastname' => 'Correo',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-15',
            'gender' => Gender::MALE,
            'street' => 'Calle Test',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'Nuevo Leon',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 100000,
            'customer_id' => $user->customer->id,
            'preferred_laboratory_store_id' => $store->id,
        ]);

        $data = LaboratoryPurchaseConfirmationViewData::build($purchase->fresh(), $user);

        $this->assertTrue($data['has_preferred_store']);
        $this->assertSame('Pastora', $data['preferred_store_name']);
        $this->assertSame('Eloy Cavazos, Las Villas, Guadalupe, 67175', $data['preferred_store_address']);
        $this->assertSame('8180001234', $data['preferred_store_phone']);
        $this->assertStringContainsString('Lun-vie: 7:00 a 15:00', $data['preferred_store_hours']);
        $this->assertSame('https://maps.google.com/?q=pastora', $data['preferred_store_google_maps_url']);
    }

    public function test_build_omits_preferred_store_when_not_selected(): void
    {
        $user = User::factory()->withCompleteProfile()->withRegularCustomer()->create([
            'documentation_accepted_at' => now(),
        ])->fresh('customer');

        $purchase = LaboratoryPurchase::query()->create([
            'brand' => LaboratoryBrand::SWISSLAB,
            'gda_order_id' => 'GDA-NO-PREF-001',
            'name' => 'Paciente',
            'paternal_lastname' => 'Sin',
            'maternal_lastname' => 'Sucursal',
            'phone' => '8111111111',
            'phone_country' => 'MX',
            'birth_date' => '1990-01-15',
            'gender' => Gender::MALE,
            'street' => 'Calle Test',
            'number' => '1',
            'neighborhood' => 'Centro',
            'state' => 'Nuevo Leon',
            'city' => 'Monterrey',
            'zipcode' => '64000',
            'total_cents' => 100000,
            'customer_id' => $user->customer->id,
        ]);

        $data = LaboratoryPurchaseConfirmationViewData::build($purchase->fresh(), $user);

        $this->assertFalse($data['has_preferred_store']);
        $this->assertArrayNotHasKey('preferred_store_name', $data);
        $this->assertArrayHasKey('branches_url', $data);
    }
}
