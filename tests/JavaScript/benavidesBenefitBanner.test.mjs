import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { test } from "node:test";

const source = readFileSync(
	new URL("../../resources/js/Components/Benefits/BenavidesBenefitBanner.jsx", import.meta.url),
	"utf8",
);
const modalSource = readFileSync(
	new URL("../../resources/js/Components/Benefits/BenavidesBenefitModal.jsx", import.meta.url),
	"utf8",
);
const homeSource = readFileSync(
	new URL("../../resources/js/Pages/Home.jsx", import.meta.url),
	"utf8",
);

test("Benavides banner hides when promotion does not apply", () => {
	assert.match(source, /if \(!benefit\?\.promotionEnabled\) return false;/);
	assert.match(source, /return Boolean\(benefit\.activationEnabled && benefit\.hasAvailableCodes\);/);
	assert.match(source, /if \(!shouldShowBenefit\(benefit\)\) return null;/);
});

test("Benavides banner uses the expected CTA labels", () => {
	assert.match(source, /"Ver mi credencial"/);
	assert.match(source, /"Quiero mi beneficio"/);
	assert.match(source, /route\("user\.benefits\.benavides\.show"\)/);
});

test("Benavides promotion modal renders only when backend allows it", () => {
	assert.match(modalSource, /Boolean\(benefit\?\.showPromotionModal\)/);
	assert.match(modalSource, /if \(!benefit\?\.showPromotionModal\) return null;/);
	assert.match(homeSource, /<BenavidesBenefitModal benefit=\{benavidesBenefit\} \/>/);
	assert.match(homeSource, /<BenavidesBenefitBanner benefit=\{benavidesBenefit\} \/>/);
});

test("Benavides promotion modal tracks dismiss and click with expected CTAs", () => {
	assert.match(modalSource, /"Quiero mi beneficio"/);
	assert.match(modalSource, /"Ver después"/);
	assert.match(modalSource, /route\("user\.benefits\.benavides\.promotion\.dismiss"\)/);
	assert.match(modalSource, /route\("user\.benefits\.benavides\.promotion\.click"\)/);
	assert.match(modalSource, /router\.visit\(route\("user\.benefits\.benavides\.show"\)\)/);
	assert.match(modalSource, /processing === "dismiss"/);
	assert.match(modalSource, /processing === "click"/);
});
