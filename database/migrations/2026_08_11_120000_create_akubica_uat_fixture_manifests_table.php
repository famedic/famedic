<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('akubica_uat_fixture_manifests')) {
            return;
        }

        Schema::create('akubica_uat_fixture_manifests', function (Blueprint $table): void {
            $table->id();
            $table->string('namespace', 64)->unique();
            $table->unsignedSmallInteger('fixture_version');
            $table->string('status', 24);
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('akubica_uat_fixture_manifests');
    }
};
