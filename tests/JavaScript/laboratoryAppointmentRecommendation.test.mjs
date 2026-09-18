import assert from "node:assert/strict";
import test from "node:test";

import { appointmentStoreRecommendationPresentation } from "../../resources/js/lib/laboratoryAppointmentRecommendation.js";

const recommendation = (overrides = {}) => ({
	store: {
		id: 1,
		name: "Sucursal Cumbres",
		brand: "swisslab",
		brand_label: "Swisslab",
	},
	validation: { status: "valid" },
	can_use: true,
	...overrides,
});

test("shows valid selected store as a patient recommendation that can be used", () => {
	const presentation = appointmentStoreRecommendationPresentation(
		recommendation(),
	);

	assert.equal(presentation.title, "Sucursal recomendada por el paciente");
	assert.equal(presentation.canUse, true);
	assert.equal(presentation.actionLabel, "Usar esta sucursal");
	assert.equal(presentation.tone, "success");
});

test("hides recommendation when there is no selected store", () => {
	assert.equal(appointmentStoreRecommendationPresentation(null), null);
	assert.equal(
		appointmentStoreRecommendationPresentation({ store: null }),
		null,
	);
});

test("marks stale selected store for review without allowing direct use", () => {
	const presentation = appointmentStoreRecommendationPresentation(
		recommendation({
			validation: { status: "stale", reason: "cart_hash_changed" },
			can_use: false,
		}),
	);

	assert.equal(presentation.title, "La recomendación necesita revisión");
	assert.equal(presentation.canUse, false);
	assert.equal(presentation.actionLabel, null);
});

test("does not claim unknown compatibility as compatible", () => {
	const presentation = appointmentStoreRecommendationPresentation(
		recommendation({
			validation: { status: "unknown", reason: "cart_not_resolvable" },
			can_use: false,
		}),
	);

	assert.equal(presentation.tone, "warning");
	assert.equal(presentation.canUse, false);
	assert.match(presentation.detail, /Revisa manualmente/);
});
