import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

test("marketing campaign hero uploads use the configured media disk", () => {
	const service = readFileSync(
		"app/Services/Marketing/MarketingCampaignHeroImageService.php",
		"utf8",
	);
	const config = readFileSync("config/marketing-campaigns.php", "utf8");

	assert.match(service, /config\('marketing-campaigns\.media\.disk', 'public'\)/);
	assert.match(service, /config\('marketing-campaigns\.media\.visibility', 'public'\)/);
	assert.match(config, /MARKETING_CAMPAIGN_MEDIA_DISK/);
	assert.match(config, /MARKETING_CAMPAIGN_MEDIA_VISIBILITY/);
});

test("marketing campaign gallery uploads are stored with public visibility", () => {
	const service = readFileSync(
		"app/Services/Marketing/MarketingCampaignLinkImageService.php",
		"utf8",
	);

	assert.match(service, /uploadDisk\(\)/);
	assert.match(service, /uploadVisibility\(\)/);
});

test("marketing campaign product uploads use the campaign media disk", () => {
	const service = readFileSync(
		"app/Services/Marketing/MarketingCampaignLinkProductService.php",
		"utf8",
	);

	assert.match(service, /uploadDisk\(\)/);
	assert.match(service, /uploadVisibility\(\)/);
});

test("admin live preview uses selected product image preview urls", () => {
	const preview = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLandingPreview.jsx",
		"utf8",
	);
	const wizard = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/MarketingCampaignSetupWizard.jsx",
		"utf8",
	);
	const card = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignProductCard.jsx",
		"utf8",
	);
	const setupRequest = readFileSync(
		"app/Http/Requests/Admin/MarketingCampaigns/StoreMarketingCampaignSetupRequest.php",
		"utf8",
	);

	assert.match(preview, /product\.image_preview_url/);
	assert.match(wizard, /product\.image_preview_url/);
	assert.match(card, /product\.image_preview_url/);
	assert.match(setupRequest, /primary_product_image_uploads/);
	assert.match(setupRequest, /decodeJsonArray\(\$link\[\$field\]/);
});
