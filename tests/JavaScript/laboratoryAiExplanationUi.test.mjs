import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

const blockSource = readFileSync(
	"resources/js/Components/LaboratoryOrderDetail/StructuredResultAiExplanationBlock.jsx",
	"utf8",
);
const consentSource = readFileSync(
	"resources/js/Components/LaboratoryOrderDetail/AiExplanationConsentDialog.jsx",
	"utf8",
);
const rowSource = readFileSync(
	"resources/js/Components/LaboratoryOrderDetail/StructuredResultObservationRow.jsx",
	"utf8",
);
const hookSource = readFileSync("resources/js/hooks/useLaboratoryObservationAiExplanation.js", "utf8");
const panelSource = readFileSync(
	"resources/js/Components/LaboratoryOrderDetail/StructuredResultsPanel.jsx",
	"utf8",
);

test("observation row exposes AI action without clinical gating", () => {
	assert.match(rowSource, /StructuredResultAiExplanationBlock/);
	assert.match(rowSource, /aiExplanationEnabled/);
	assert.doesNotMatch(blockSource, /status\s*===\s*["']high["']/);
	assert.doesNotMatch(blockSource, /observation\?\.status/);
	assert.doesNotMatch(blockSource, /observation\?\.abnormal/);
});

test("AI block shows action, loading, ready, failed, and invalid states", () => {
	assert.match(blockSource, /Entender este resultado/);
	assert.match(blockSource, /Estamos preparando una explicación de este resultado/);
	assert.match(blockSource, /aria-busy="true"/);
	assert.match(blockSource, /Explicación/);
	assert.match(blockSource, /No pudimos preparar la explicación en este momento/);
	assert.match(blockSource, /No fue posible generar una explicación segura/);
	assert.match(blockSource, /Intentar nuevamente/);
	assert.doesNotMatch(blockSource, /openai/i);
	assert.doesNotMatch(blockSource, /input_hash/);
	assert.doesNotMatch(blockSource, /ai_execution_id/);
});

test("consent dialog uses approved copy and decline does not request explanation", () => {
	assert.match(consentSource, /Entender mis resultados con ayuda de IA/);
	assert.match(consentSource, /no sustituye la valoración de un profesional/);
	assert.match(consentSource, /Al continuar, aceptas el uso de los datos mínimos/);
	assert.match(consentSource, /Continuar/);
	assert.match(consentSource, /Ahora no/);
	assert.doesNotMatch(consentSource, /diagnóstico/i);
});

test("hook polls only processing states and cleans up timers", () => {
	assert.match(hookSource, /AI_EXPLANATION_POLL_INTERVAL_MS/);
	assert.match(hookSource, /AI_EXPLANATION_POLL_MAX_MS/);
	assert.match(hookSource, /isAiExplanationProcessingStatus/);
	assert.match(hookSource, /clearInterval/);
	assert.match(hookSource, /abortControllerRef/);
	assert.match(hookSource, /observationId/);
	assert.match(hookSource, /requestLaboratoryAiExplanation/);
	assert.match(hookSource, /updateLaboratoryAiExplanationConsent/);
	assert.doesNotMatch(hookSource, /localStorage/);
});

test("structured panel passes purchase and feature flag meta", () => {
	assert.match(panelSource, /purchaseId/);
	assert.match(panelSource, /ai_explanation_enabled/);
	assert.match(panelSource, /observation\?\.id/);
});

test("UI never sends clinical payload from frontend", () => {
	assert.doesNotMatch(blockSource, /reference_status/);
	assert.doesNotMatch(blockSource, /abnormal_flag/);
	assert.doesNotMatch(hookSource, /numeric_value/);
});
