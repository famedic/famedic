<?php

use App\Enums\MarketingCampaignLandingTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->string('landing_template', 40)
                ->default(MarketingCampaignLandingTemplate::Conversion->value)
                ->after('landing_layout');

            $table->index('landing_template', 'mc_links_landing_template_idx');
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaign_links', function (Blueprint $table) {
            $table->dropIndex('mc_links_landing_template_idx');
            $table->dropColumn('landing_template');
        });
    }
};
