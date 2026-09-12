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
