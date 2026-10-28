import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

import {
	fetchLaboratoryStructuredResults,
	formatStructuredObservationValue,
	formatStructuredReference,
	getStructuredObservationStatusPresentation,
	STRUCTURED_RESULTS_FETCH_STATE,
} from "../../resources/js/lib/laboratoryStructuredPatientResults.js";

test("formats structured observation value with unit", () => {
	assert.equal(
		formatStructuredObservationValue({ value: 108, value_type: "numeric", unit: "mg/dL" }),
		"108 mg/dL",
	);
});

test("formats structured reference from text first", () => {
	assert.equal(formatStructuredReference({ text: "70-100", low: 70, high: 100 }, "mg/dL"), "70-100");
});

test("formats structured reference from low and high when text is missing", () => {
	assert.equal(formatStructuredReference({ low: 13, high: 17 }, "g/dL"), "13–17 g/dL");
});

test("returns null reference when no data exists", () => {
	assert.equal(formatStructuredReference(null), null);
	assert.equal(formatStructuredReference({}), null);
});

test("maps observation status presentation without clinical interpretation", () => {
	assert.deepEqual(getStructuredObservationStatusPresentation("normal", false), {
		label: "En rango",
		showIndicator: true,
		badgeColor: "green",
		icon: "check",
	});
	assert.deepEqual(getStructuredObservationStatusPresentation("low", true), {
		label: "Fuera del rango",
		showIndicator: true,
		badgeColor: "amber",
		icon: "warning",
	});
	assert.deepEqual(getStructuredObservationStatusPresentation("high", true), {
		label: "Fuera del rango",
		showIndicator: true,
		badgeColor: "amber",
		icon: "warning",
	});
	assert.deepEqual(getStructuredObservationStatusPresentation("unknown", false), {
		label: "No se pudo determinar",
		showIndicator: true,
		badgeColor: "zinc",
		icon: "info",
	});
	assert.deepEqual(getStructuredObservationStatusPresentation("not_applicable", false), {
		label: null,
		showIndicator: false,
		badgeColor: "zinc",
		icon: null,
	});
});

test("fetchLaboratoryStructuredResults handles published, empty, auth, forbidden, and server errors", async () => {
	const routeFn = () => "https://famedic.test/structured-results";

	const available = await fetchLaboratoryStructuredResults(10, {
		routeFn,
		fetchFn: async () => ({
			ok: true,
			status: 200,
			json: async () => ({
				data: {
					report: { id: 1 },
					observations: [{ analyte: { name: "Glucosa" }, value: 108, status: "high" }],
				},
			}),
		}),
	});
	assert.equal(available.state, STRUCTURED_RESULTS_FETCH_STATE.AVAILABLE);
	assert.equal(available.data.observations.length, 1);

	const unavailable = await fetchLaboratoryStructuredResults(10, {
		routeFn,
		fetchFn: async () => ({ ok: false, status: 404, json: async () => ({}) }),
	});
	assert.equal(unavailable.state, STRUCTURED_RESULTS_FETCH_STATE.UNAVAILABLE);

	const unauthorized = await fetchLaboratoryStructuredResults(10, {
		routeFn,
		fetchFn: async () => ({ ok: false, status: 401, json: async () => ({}) }),
	});
	assert.equal(unauthorized.state, STRUCTURED_RESULTS_FETCH_STATE.UNAUTHORIZED);

	const forbidden = await fetchLaboratoryStructuredResults(10, {
		routeFn,
		fetchFn: async () => ({ ok: false, status: 403, json: async () => ({}) }),
	});
	assert.equal(forbidden.state, STRUCTURED_RESULTS_FETCH_STATE.FORBIDDEN);

	const error = await fetchLaboratoryStructuredResults(10, {
		routeFn,
		fetchFn: async () => ({ ok: false, status: 500, json: async () => ({}) }),
	});
	assert.equal(error.state, STRUCTURED_RESULTS_FETCH_STATE.ERROR);
});

test("structured results UI uses one endpoint and does not expose internal metadata", () => {
	const orderDetailSource = readFileSync("resources/js/Pages/LaboratoryOrderDetail.jsx", "utf8");
	const resultsSectionSource = readFileSync(
		"resources/js/Components/LaboratoryOrderDetail/ResultsSection.jsx",
		"utf8",
	);
	const panelSource = readFileSync(
		"resources/js/Components/LaboratoryOrderDetail/StructuredResultsPanel.jsx",
		"utf8",
	);
	const observationSource = readFileSync(
		"resources/js/Components/LaboratoryOrderDetail/StructuredResultObservationRow.jsx",
		"utf8",
	);
	const hookSource = readFileSync("resources/js/hooks/useLaboratoryStructuredResults.js", "utf8");
	const libSource = readFileSync("resources/js/lib/laboratoryStructuredPatientResults.js", "utf8");

	assert.match(orderDetailSource, /useLaboratoryStructuredResults/);
	assert.match(hookSource, /fetchLaboratoryStructuredResults/);
	assert.match(libSource, /laboratory-purchases\.structured-results/);
	assert.doesNotMatch(hookSource, /observations\.map\(async/);
	assert.doesNotMatch(hookSource, /for \(const observation/);

	assert.match(resultsSectionSource, /StructuredResultsPanel/);
	assert.match(resultsSectionSource, /Ver resultado original/);
	assert.match(panelSource, /Los resultados estructurados aún no están disponibles/);
	assert.match(panelSource, /animate-pulse/);
	assert.match(panelSource, /No pudimos cargar tus resultados estructurados/);

	assert.match(observationSource, /getStructuredObservationStatusPresentation/);
	assert.match(observationSource, /Referencia:/);
	assert.match(observationSource, /aria-label/);
	assert.doesNotMatch(observationSource, /raw_extraction_payload/);
	assert.doesNotMatch(observationSource, /OpenAI/i);
	assert.doesNotMatch(observationSource, /significa/i);
	assert.doesNotMatch(observationSource, /enfermedad/i);
});

test("structured results panel keeps original pdf action separate", () => {
	const resultsSectionSource = readFileSync(
		"resources/js/Components/LaboratoryOrderDetail/ResultsSection.jsx",
		"utf8",
	);

	assert.match(resultsSectionSource, /Documento original/);
	assert.match(resultsSectionSource, /onViewResults/);
	assert.doesNotMatch(resultsSectionSource, /pdf_base64/);
	assert.doesNotMatch(resultsSectionSource, /structured-results.*pdf/i);
});
