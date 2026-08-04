<?php

namespace Tests\Feature\PayPal;

use Tests\TestCase;

class MedicalAttentionPayPalRouteTest extends TestCase
{
    public function test_medical_attention_paypal_create_order_requires_authentication(): void
    {
        $response = $this->postJson('/medical-attention/paypal/create-order');

        $response->assertStatus(401);
    }

    public function test_medical_attention_paypal_capture_order_requires_authentication(): void
    {
        $response = $this->postJson('/medical-attention/paypal/capture-order', [
            'order_id' => 'TEST-ORDER',
        ]);

        $response->assertStatus(401);
    }
}
