import assert from "node:assert/strict";
import test from "node:test";

import { laboratoryBrandSelectionActions } from "../../resources/js/lib/laboratoryBrandSelectionActions.js";

const brands = ["olab", "swisslab", "jenner", "liacsa", "azteca"];

function routeSpy(name, params) {
	return { name, params };
}

test("builds primary studies and secondary stores actions for every laboratory brand", () => {
	for (const brand of brands) {
		const actions = laboratoryBrandSelectionActions(routeSpy, brand);

		assert.equal(actions.studies.label, "Ver estudios");
		assert.deepEqual(actions.studies.href, {
			name: "laboratory-tests",
			params: { laboratory_brand: brand },
		});
		assert.equal(actions.studies.ariaLabel, `Ver estudios de ${brand}`);

		assert.equal(actions.stores.label, "Ver sucursales");
		assert.deepEqual(actions.stores.href, {
			name: "laboratory-stores.index",
			params: { brand },
		});
		assert.equal(actions.stores.ariaLabel, `Ver sucursales de ${brand}`);
	}
});

test("preserves category only for the studies flow", () => {
	const actions = laboratoryBrandSelectionActions(
		routeSpy,
		"olab",
		"12",
		"Olab",
	);

	assert.deepEqual(actions.studies.href, {
		name: "laboratory-tests",
		params: { laboratory_brand: "olab", category: "12" },
	});
	assert.equal(actions.studies.ariaLabel, "Ver estudios de Olab");
	assert.deepEqual(actions.stores.href, {
		name: "laboratory-stores.index",
		params: { brand: "olab" },
	});
	assert.equal(actions.stores.ariaLabel, "Ver sucursales de Olab");
});
