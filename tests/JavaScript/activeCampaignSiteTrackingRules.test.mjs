import assert from "node:assert/strict";
import test from "node:test";

import {
	sanitizeTrackingUrl,
	shouldTrack,
} from "../../resources/js/lib/activeCampaignSiteTrackingRules.js";

test("allows public commercial pages and checkout steps without sensitive query data", () => {
	assert.equal(shouldTrack("/laboratories"), true);
	assert.equal(shouldTrack("/checkout/payment"), true);
});

test("blocks admin, api, auth, webhook, fiscal, and 3ds surfaces", () => {
	assert.equal(shouldTrack("/admin/carts"), false);
	assert.equal(shouldTrack("/api/efevoopay/payment"), false);
	assert.equal(shouldTrack("/login"), false);
	assert.equal(shouldTrack("/magic-login/123"), false);
	assert.equal(shouldTrack("/payment-methods/3ds/callback"), false);
	assert.equal(shouldTrack("/tax-profiles"), false);
	assert.equal(shouldTrack("/webhooks/activecampaign"), false);
});

test("blocks token, otp, and result URLs", () => {
	assert.equal(shouldTrack("/checkout/payment?token=abc"), false);
	assert.equal(shouldTrack("/laboratory/olab/checkout?otp=123456"), false);
	assert.equal(shouldTrack("/laboratory-purchases/42/results"), false);
	assert.equal(shouldTrack("/verify-phone/confirmation"), false);
	assert.equal(shouldTrack("/reset-password/abc"), false);
});

test("sanitizes URLs by removing query strings and hashes", () => {
	assert.equal(
		sanitizeTrackingUrl(
			"/checkout?token=abc#step",
			"https://famedic.com.mx",
		),
		"https://famedic.com.mx/checkout",
	);

	assert.equal(
		sanitizeTrackingUrl("https://famedic.com.mx/laboratories?utm_source=x"),
		"https://famedic.com.mx/laboratories",
	);
});
