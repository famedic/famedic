<?php

namespace Tests\Feature\Laboratory;

use App\Models\LaboratoryPurchaseConfirmation as Confirmation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LaboratoryConfirmationMigrationTest extends TestCase
{
    #[Test]
    public function additive_schema_enforces_unique_foreign_key_and_encrypted_payload_and_reapplies(): void
    {
        $this->assertSame('mysql', DB::getDriverName());
        Schema::create('laboratory_purchases', function (Blueprint $table) { $table->id(); $table->string('historical_marker'); });
        DB::table('laboratory_purchases')->insert(['id' => 1, 'historical_marker' => 'UNCHANGED_HISTORY']);
        $create = require base_path('database/migrations/2026_10_16_200000_create_laboratory_purchase_confirmations_table.php');
        $version = require base_path('database/migrations/2026_10_16_210000_add_snapshot_version_to_laboratory_purchase_confirmations.php');
        $create->up(); $version->up();
        $intent = Confirmation::create(['laboratory_purchase_id' => 1, 'event_type' => 'purchase_confirmation',
            'idempotency_key' => 'QA-UNIQUE', 'confirmed_at' => now(), 'deadline_at' => now()->addSeconds(15),
            'snapshot_version' => (string) Str::uuid(), 'payload' => ['html' => 'AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML']]);
        $raw = DB::table('laboratory_purchase_confirmations')->where('id', $intent->id)->value('payload');
        $this->assertStringNotContainsString('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML', $raw);
        $this->assertSame('AUDIT_CLINICAL_CANARY_SYNTHETIC_25ML', $intent->fresh()->payload['html']);
        $this->assertArrayNotHasKey('payload', $intent->fresh()->toArray());
        try { DB::table('laboratory_purchase_confirmations')->insert(['laboratory_purchase_id' => 1,
            'event_type' => 'purchase_confirmation', 'idempotency_key' => 'QA-UNIQUE', 'confirmed_at' => now(), 'deadline_at' => now()]); $this->fail('UNIQUE required'); }
        catch (\Illuminate\Database\QueryException $exception) { $this->assertSame('23000', $exception->errorInfo[0]); }
        try { DB::table('laboratory_purchases')->where('id', 1)->delete(); $this->fail('FK must restrict deletion'); }
        catch (\Illuminate\Database\QueryException $exception) { $this->assertSame('23000', $exception->errorInfo[0]); }
        $version->down(); $version->up();
        $this->assertNotNull($intent->fresh()->snapshot_version);
        $this->assertSame($raw, DB::table('laboratory_purchase_confirmations')->where('id', $intent->id)->value('payload'));
        $version->down(); $create->down();
        $this->assertFalse(Schema::hasTable('laboratory_purchase_confirmations'));
        $this->assertSame('UNCHANGED_HISTORY', DB::table('laboratory_purchases')->value('historical_marker'));
        $create->up(); $version->up();
        $this->assertTrue(Schema::hasColumn('laboratory_purchase_confirmations', 'snapshot_version'));
        $this->assertSame(0, Confirmation::count());
        $version->down(); $create->down(); Schema::drop('laboratory_purchases');
    }
}
