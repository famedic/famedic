<?php

declare(strict_types=1);

$purchaseId = (int) ($argv[1] ?? 0);
$dryRun = in_array('--dry-run', $argv, true);
$process = ! in_array('--no-process', $argv, true);

if ($purchaseId <= 0) {
    fwrite(STDERR, "Usage: php scripts/ac-sync-prep-instructions-purchase.php <purchase_id> [--dry-run] [--no-process]\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$purchase = App\Models\LaboratoryPurchase::query()
    ->with(['laboratoryPurchaseItems', 'cart', 'preparationSummary.aiExecution', 'customer.user'])
    ->find($purchaseId);

if (! $purchase) {
    fwrite(STDERR, "PURCHASE_NOT_FOUND: {$purchaseId}\n");
    exit(1);
}

$email = $purchase->customer?->user?->email ?? '(no email)';
echo "purchase_id={$purchase->id} email={$email}\n";
echo 'field_id_config='.(config('services.activecampaign.fields.lab.estudios_instrucciones') ?? 'null')."\n";

$fields = app(App\Services\ActiveCampaign\LaboratoryActiveCampaignPayloadBuilder::class)
    ->forPreparationInstructions($purchase);

if ($fields === []) {
    fwrite(STDERR, "EMPTY_INSTRUCTIONS_PAYLOAD\n");
    exit(2);
}

echo 'instructions_preview='.mb_substr((string) $fields['estudios_instrucciones'], 0, 200)."\n";

if ($dryRun) {
    echo "DRY_RUN_OK\n";
    exit(0);
}

app(App\Services\ActiveCampaign\LaboratoryPreparationActiveCampaignSync::class)->syncInstructions(
    $purchase,
    App\Services\ActiveCampaign\LaboratoryPreparationActiveCampaignSync::TRIGGER_CONFIRMATION_READY,
);

$dispatch = App\Models\ActiveCampaignDispatch::query()
    ->where('idempotency_key', "laboratory_purchase:{$purchase->id}:preparation_instructions:lab_fields")
    ->latest('id')
    ->first();

if (! $dispatch) {
    fwrite(STDERR, "DISPATCH_NOT_CREATED (cart missing or outbox disabled)\n");
    exit(3);
}

echo "dispatch_id={$dispatch->id} status={$dispatch->status}\n";

if ($process && $dispatch->status === App\Models\ActiveCampaignDispatch::STATUS_PENDING) {
    try {
        (new App\Jobs\ActiveCampaign\DispatchActiveCampaignOutboundJob($dispatch->id))->handle(
            app(App\Services\ActiveCampaign\ActiveCampaignService::class),
        );
        $dispatch = $dispatch->fresh();
        echo "after_process status={$dispatch->status}\n";
        if ($dispatch->last_error) {
            echo "last_error={$dispatch->last_error}\n";
        }
    } catch (Throwable $e) {
        fwrite(STDERR, 'PROCESS_FAILED: '.$e->getMessage()."\n");
        exit(4);
    }
}

exit($dispatch->status === App\Models\ActiveCampaignDispatch::STATUS_SYNCED ? 0 : 5);