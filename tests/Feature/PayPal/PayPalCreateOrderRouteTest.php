<?php

namespace Tests\Feature\PayPal;

use Tests\TestCase;

/**
 * Comprueba que las rutas PayPal existen y responden (sin sandbox real).
 */
class PayPalCreateOrderRouteTest extends TestCase
{
    public function test_paypal_create_order_requires_authentication(): void
    {
        $response = $this->postJson('/paypal/create-order', [
            'address_id' => 1,
            'laboratory_brand' => 'olab',
            'total' => 100,
        ]);

        $response->assertStatus(401);
    }
}
