import assert from "node:assert/strict";
import test from "node:test";

import {
	MARKETING_CAMPAIGN_IMAGE_MAX_BYTES,
	MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES,
	formatFileSize,
	validateMarketingCampaignImageFile,
} from "../../resources/js/Pages/Admin/MarketingCampaigns/Components/imageFileValidation.js";

test("marketing campaign image validation blocks files above the backend limit", () => {
	const result = validateMarketingCampaignImageFile({
		size: MARKETING_CAMPAIGN_IMAGE_MAX_BYTES + 1,
	});

	assert.equal(result.valid, false);
	assert.equal(result.severity, "error");
	assert.match(result.message, /límite permitido es 5 MB/);
});

test("marketing campaign image validation warns above the recommended size", () => {
	const result = validateMarketingCampaignImageFile({
		size: MARKETING_CAMPAIGN_IMAGE_RECOMMENDED_BYTES + 1,
	});

	assert.equal(result.valid, true);
	assert.equal(result.severity, "warning");
	assert.match(result.message, /Sí puede subirse/);
});

test("marketing campaign image validation accepts optimized files", () => {
	const result = validateMarketingCampaignImageFile({ size: 900 * 1024 });

	assert.equal(result.valid, true);
	assert.equal(result.severity, "success");
	assert.match(result.message, /Imagen lista para subir/);
});

test("formatFileSize renders readable values", () => {
	assert.equal(formatFileSize(512), "512 bytes");
	assert.equal(formatFileSize(1024), "1 KB");
	assert.equal(formatFileSize(5 * 1024 * 1024), "5 MB");
});
