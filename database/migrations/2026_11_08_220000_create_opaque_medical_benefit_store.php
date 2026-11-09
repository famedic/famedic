<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Unsupported benefit revision driver');
        }
        foreach (['medical_service_carts', 'coupons', 'coupon_user'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unsignedBigInteger('opaque_revision')->default(0));
        }
        Schema::create('opaque_benefit_mutex', function (Blueprint $t) {
            $t->unsignedInteger('id')->primary();
            $t->unsignedBigInteger('generation')->default(0);
        });
        DB::table('opaque_benefit_mutex')->insert(['id' => 1, 'generation' => 0]);
        Schema::create('opaque_benefit_contexts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('cart_id')->constrained('medical_service_carts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('consumer_id')->constrained('api_consumers')->cascadeOnDelete();
            $t->foreignId('channel_id')->constrained('api_consumer_channels')->cascadeOnDelete();
            $t->foreignId('delegation_id')->constrained('api_consumer_delegations')->cascadeOnDelete();
            $t->char('actor_hash', 64);
            $t->char('cart_ref_hash', 64)->unique();
            $t->text('cart_ref_ciphertext');
            $t->text('version_ciphertext');
            $t->char('fingerprint', 64);
            $t->unsignedBigInteger('revision');
            $t->timestamps();
            $t->unique(['cart_id', 'actor_hash'], 'opaque_cart_actor_unique');
        });
        Schema::create('opaque_benefit_states', function (Blueprint $t) {
            $t->id();
            $t->string('bucket', 20);
            $t->char('key_hash', 64);
            $t->foreignId('context_id')->constrained('opaque_benefit_contexts')->cascadeOnDelete();
            $t->foreignId('source_id')->nullable()->constrained('coupons')->nullOnDelete();
            $t->text('ciphertext');
            $t->timestamps();
            $t->unique(['bucket', 'key_hash'], 'opaque_state_identity_unique');
            $t->index(['context_id', 'bucket']);
        });
        Schema::create('opaque_benefit_settlements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('context_id')->constrained('opaque_benefit_contexts')->restrictOnDelete();
            $t->foreignId('transaction_id')->unique()->constrained('transactions')->restrictOnDelete();
            $t->foreignId('source_id')->constrained('coupons')->restrictOnDelete();
            $t->char('purchase_ref_hash', 64)->unique();
            $t->unsignedBigInteger('credit_cents');
            $t->unsignedBigInteger('subtotal_cents');
            $t->char('application_hash', 64);
            $t->char('source_stamp', 64);
            $t->timestamps();
        });
        Schema::create('opaque_benefit_movements', function (Blueprint $t) {
            $t->id();
            $t->foreignId('settlement_id')->constrained('opaque_benefit_settlements')->restrictOnDelete();
            $t->foreignId('source_id')->constrained('coupons')->restrictOnDelete();
            $t->foreignId('credit_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $t->enum('kind', ['debit', 'reversal']);
            $t->unsignedBigInteger('amount_cents');
            $t->unsignedBigInteger('remaining_before');
            $t->unsignedBigInteger('remaining_after');
            $t->unsignedBigInteger('source_revision_after');
            $t->unsignedBigInteger('assignment_revision_after');
            $t->timestamp('created_at');
            $t->unique(['settlement_id', 'kind'], 'opaque_movement_once');
        });
        $this->triggers();
    }

    private function triggers(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';
        foreach (['medical_service_cart_items' => 'obi', 'medical_service_cart_benefits' => 'obb'] as $table => $prefix) {
            foreach (['INSERT' => 'NEW', 'DELETE' => 'OLD'] as $event => $row) {
                $name = $prefix.'_'.strtolower($event);
                DB::unprepared("CREATE TRIGGER $name AFTER $event ON $table FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=$row.cart_id; END");
            }
            DB::unprepared("CREATE TRIGGER {$prefix}_update AFTER UPDATE ON $table FOR EACH ROW BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=OLD.cart_id OR id=NEW.cart_id; END");
        }
        foreach (['coupons' => 'ob_source', 'coupon_user' => 'ob_assignment'] as $table => $name) {
            if ($sqlite) {
                DB::unprepared("CREATE TRIGGER $name AFTER UPDATE ON $table FOR EACH ROW WHEN NEW.opaque_revision=OLD.opaque_revision BEGIN UPDATE $table SET opaque_revision=opaque_revision+1 WHERE id=NEW.id; END");
            } else {
                DB::unprepared("CREATE TRIGGER $name BEFORE UPDATE ON $table FOR EACH ROW SET NEW.opaque_revision=OLD.opaque_revision+1");
            }
        }
        $cols = ['status', 'subtotal_cents', 'discount_cents', 'credit_applied_cents', 'total_cents'];
        if ($sqlite) {
            $condition = implode(' OR ', array_map(fn ($c) => "OLD.$c IS NOT NEW.$c", $cols));
            DB::unprepared("CREATE TRIGGER obc_update AFTER UPDATE ON medical_service_carts FOR EACH ROW WHEN $condition BEGIN UPDATE medical_service_carts SET opaque_revision=opaque_revision+1 WHERE id=NEW.id; END");
        } else {
            $condition = implode(' OR ', array_map(fn ($c) => "NOT(OLD.$c <=> NEW.$c)", $cols));
            DB::unprepared("CREATE TRIGGER obc_update BEFORE UPDATE ON medical_service_carts FOR EACH ROW BEGIN IF $condition THEN SET NEW.opaque_revision=OLD.opaque_revision+1; END IF; END");
        }
    }

    public function down(): void
    {
        foreach (['obi_insert', 'obi_delete', 'obi_update', 'obb_insert', 'obb_delete', 'obb_update', 'obc_update', 'ob_source', 'ob_assignment'] as $name) {
            DB::unprepared("DROP TRIGGER IF EXISTS $name");
        }
        foreach (['opaque_benefit_movements', 'opaque_benefit_settlements', 'opaque_benefit_states', 'opaque_benefit_contexts', 'opaque_benefit_mutex'] as $table) {
            Schema::dropIfExists($table);
        }
        foreach (['medical_service_carts', 'coupons', 'coupon_user'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('opaque_revision'));
        }
    }
};
