<?php

declare(strict_types=1);

$email = $argv[1] ?? 'emedina.externo@odessa.com.mx';

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$user = App\Models\User::query()->where('email', $email)->first();
if (! $user) {
    fwrite(STDERR, "USER_NOT_FOUND: {$email}\n");
    exit(1);
}

echo "user_id={$user->id}\n";
echo 'ac_enabled='.(app(App\Services\ActiveCampaign\ActiveCampaignService::class)->enabled() ? 'yes' : 'no')."\n";
echo 'cart_outbox='.(app(App\Services\ActiveCampaign\ActiveCampaignOutboundDispatcher::class)->isCartOutboxEnabled() ? 'yes' : 'no')."\n";

$purchases = App\Models\LaboratoryPurchase::query()
    ->where(function ($query) use ($user) {
        $query->where('customer_id', $user->customer_id)
            ->orWhereHas('customer.user', fn ($q) => $q->where('email', $user->email));
    })
    ->latest('id')
    ->limit(5)
    ->with(['laboratoryPurchaseItems', 'cart', 'preparationSummary', 'customer.user'])
    ->get();

$cartPurchases = App\Models\LaboratoryPurchase::query()
    ->whereHas('cart.user', fn ($q) => $q->where('email', $user->email))
    ->latest('id')
    ->limit(5)
    ->with(['laboratoryPurchaseItems', 'cart', 'preparationSummary', 'customer.user'])
    ->get();

$purchases = $purchases->merge($cartPurchases)->unique('id')->sortByDesc('id')->values()->take(5);

if ($purchases->isEmpty()) {
    echo "NO_PURCHASES\n";
    exit(0);
}

foreach ($purchases as $purchase) {
    $brand = $purchase->brand instanceof \BackedEnum ? $purchase->brand->value : (string) $purchase->brand;
    echo "\n--- purchase_id={$purchase->id} brand={$brand} gda={$purchase->gda_order_id} ---\n";

    $fields = app(App\Services\ActiveCampaign\LaboratoryActiveCampaignPayloadBuilder::class)
        ->forPreparationInstructions($purchase);
    $text = (string) ($fields['estudios_instrucciones'] ?? '');
    echo 'instructions_len='.mb_strlen($text)."\n";
    if ($text !== '') {
        echo mb_substr($text, 0, 800).(mb_strlen($text) > 800 ? "\n...[truncated]" : '')."\n";
    }

    $dispatch = App\Models\ActiveCampaignDispatch::query()
        ->where('idempotency_key', "laboratory_purchase:{$purchase->id}:preparation_instructions:lab_fields")
        ->latest('id')
        ->first();

    if ($dispatch) {
        echo "dispatch_id={$dispatch->id} status={$dispatch->status} attempts={$dispatch->attempts}\n";
        if ($dispatch->last_error) {
            echo "last_error={$dispatch->last_error}\n";
        }
    } else {
        echo "NO_INSTRUCTIONS_DISPATCH\n";
    }
}