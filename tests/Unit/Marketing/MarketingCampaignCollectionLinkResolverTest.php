<?php

namespace Tests\Unit\Marketing;

use App\Enums\MarketingCampaignLinkStatus;
use App\Enums\MarketingCampaignTargetType;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignCollection;
use App\Models\MarketingCampaignLink;
use App\Services\Marketing\MarketingCampaignCollectionLinkResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

require_once __DIR__.'/marketingCampaignIsolatedSchema.php';

class MarketingCampaignCollectionLinkResolverTest extends TestCase
{
    protected function setUp(): void
    {
        \Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;
        parent::setUp();
        bootstrapIsolatedMarketingCampaignSchema();
    }

    protected function tearDown(): void
    {
        tearDownIsolatedMarketingCampaignSchema();
        parent::tearDown();
    }

    protected function connectionsToTransact(): array
    {
        return [];
    }

    #[Test]
    public function it_resuelve_enlaces_que_usan_una_coleccion(): void
    {
        $campaign = MarketingCampaign::factory()->create();
        $collection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();
        $otherCollection = MarketingCampaignCollection::factory()->for($campaign, 'campaign')->create();

        $link = MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'link-coleccion',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $collection->id],
        ]);

        MarketingCampaignLink::factory()->for($campaign, 'campaign')->create([
            'slug' => 'link-otra-coleccion',
            'target_type' => MarketingCampaignTargetType::Collection,
            'target_payload' => ['marketing_campaign_collection_id' => $otherCollection->id],
        ]);

        $resolver = app(MarketingCampaignCollectionLinkResolver::class);

        $this->assertSame(1, $resolver->countForCollection($collection));
        $this->assertSame($link->id, $resolver->linksForCollection($collection)->first()->id);
        $this->assertSame(
            1,
            $resolver->countsForCampaign($campaign->id, [$collection->id, $otherCollection->id])[$collection->id],
        );
    }
}
