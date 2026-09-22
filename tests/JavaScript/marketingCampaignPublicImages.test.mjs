import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

test("marketing campaign hero uploads are stored on the public disk", () => {
	const service = readFileSync(
		"app/Services/Marketing/MarketingCampaignHeroImageService.php",
		"utf8",
	);

	assert.match(service, /return 'public';/);
	assert.match(service, /'visibility' => 'public'/);
});

test("marketing campaign gallery uploads are stored with public visibility", () => {
	const service = readFileSync(
		"app/Services/Marketing/MarketingCampaignLinkImageService.php",
		"utf8",
	);

	assert.match(service, /uploadDisk\(\)/);
	assert.match(service, /'visibility' => 'public'/);
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
