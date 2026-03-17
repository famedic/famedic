<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Services\ActiveCampaign\ActiveCampaignService;

class TestActiveCampaignPurchase extends Command
{
    protected $signature = 'ac:test-purchase';
    protected $description = 'Crear compra de prueba en ActiveCampaign';

    public function handle(ActiveCampaignService $ac)
    {
        $email = 'eulalio.09@icloud.com';

        $this->info("🚀 Enviando compra de prueba para: {$email}");

        $ac->completedPurchase(
            $email,
            'TEST-' . now()->timestamp,
            100,
            [
                [
                    'name' => 'Producto prueba',
                    'price' => 100,
                    'quantity' => 1,
                    'category' => 'Test'
                ]
            ],
            'Test'
        );

        $this->info("✅ Compra enviada");
    }
}
