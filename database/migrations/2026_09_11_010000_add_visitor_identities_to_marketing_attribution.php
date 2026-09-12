<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaign_visitor_identities', function (Blueprint $table) {
            $table->id();
            $table->string('visitor_token_hash', 64);
            $table->timestamps();
        });

        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_visitor_identity_id')
                ->nullable()
                ->after('visitor_token_hash')
                ->constrained('marketing_campaign_visitor_identities', 'id', 'mc_attr_vid_fk')
                ->restrictOnDelete();

            $table->index('marketing_campaign_visitor_identity_id', 'mc_attr_vid_idx');
        });

        Schema::table('marketing_campaign_visits', function (Blueprint $table) {
            $table->foreignId('marketing_campaign_visitor_identity_id')
                ->nullable()
                ->after('visitor_token_hash')
                ->constrained('marketing_campaign_visitor_identities', 'id', 'mc_visits_vid_fk')
                ->restrictOnDelete();

            $table->index('marketing_campaign_visitor_identity_id', 'mc_visits_vid_idx');
        });

        $this->backfillIdentities();

        Schema::table('marketing_campaign_visitor_identities', function (Blueprint $table) {
            $table->unique('visitor_token_hash', 'mc_vid_token_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_visits', function (Blueprint $table) {
            $table->dropForeign('mc_visits_vid_fk');
            $table->dropIndex('mc_visits_vid_idx');
            $table->dropColumn('marketing_campaign_visitor_identity_id');
        });

        Schema::table('marketing_campaign_attributions', function (Blueprint $table) {
            $table->dropForeign('mc_attr_vid_fk');
            $table->dropIndex('mc_attr_vid_idx');
            $table->dropColumn('marketing_campaign_visitor_identity_id');
        });

        Schema::dropIfExists('marketing_campaign_visitor_identities');
    }

    private function backfillIdentities(): void
    {
        $now = now();

        // Preserve every non-empty historical hash verbatim, even if it would not
        // pass today's cookie-token format. Future cookies are validated before
        // hashing, but legacy rows still need an identity to avoid orphaned data.
        DB::insert(
            'INSERT INTO marketing_campaign_visitor_identities (visitor_token_hash, created_at, updated_at)
             SELECT visitor_token_hash, ?, ?
             FROM (
                 SELECT visitor_token_hash
                 FROM marketing_campaign_attributions
                 WHERE visitor_token_hash IS NOT NULL AND visitor_token_hash <> ?
                 UNION
                 SELECT visitor_token_hash
                 FROM marketing_campaign_visits
                 WHERE visitor_token_hash IS NOT NULL AND visitor_token_hash <> ?
             ) AS historical_hashes',
            [$now, $now, '', ''],
        );

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'UPDATE marketing_campaign_attributions AS attributions
                 INNER JOIN marketing_campaign_visitor_identities AS identities
                    ON identities.visitor_token_hash = attributions.visitor_token_hash
                 SET attributions.marketing_campaign_visitor_identity_id = identities.id
                 WHERE attributions.marketing_campaign_visitor_identity_id IS NULL'
            );

            DB::statement(
                'UPDATE marketing_campaign_visits AS visits
                 INNER JOIN marketing_campaign_visitor_identities AS identities
                    ON identities.visitor_token_hash = visits.visitor_token_hash
                 SET visits.marketing_campaign_visitor_identity_id = identities.id
                 WHERE visits.marketing_campaign_visitor_identity_id IS NULL'
            );

            return;
        }

        DB::statement(
            'UPDATE marketing_campaign_attributions
             SET marketing_campaign_visitor_identity_id = (
                 SELECT identities.id
                 FROM marketing_campaign_visitor_identities AS identities
                 WHERE identities.visitor_token_hash = marketing_campaign_attributions.visitor_token_hash
             )
             WHERE marketing_campaign_visitor_identity_id IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM marketing_campaign_visitor_identities AS identities
                   WHERE identities.visitor_token_hash = marketing_campaign_attributions.visitor_token_hash
               )'
        );

        DB::statement(
            'UPDATE marketing_campaign_visits
             SET marketing_campaign_visitor_identity_id = (
                 SELECT identities.id
                 FROM marketing_campaign_visitor_identities AS identities
                 WHERE identities.visitor_token_hash = marketing_campaign_visits.visitor_token_hash
             )
             WHERE marketing_campaign_visitor_identity_id IS NULL
               AND EXISTS (
                   SELECT 1
                   FROM marketing_campaign_visitor_identities AS identities
                   WHERE identities.visitor_token_hash = marketing_campaign_visits.visitor_token_hash
               )'
        );
    }
};
