import assert from "node:assert/strict";
import test from "node:test";
import {
	AI_EXPLANATION_POLL_INTERVAL_MS,
	AI_EXPLANATION_POLL_MAX_MS,
	AI_EXPLANATION_STATUS,
	isAiExplanationProcessingStatus,
	isAiExplanationTerminalStatus,
	mapAiExplanationHttpError,
	requestLaboratoryAiExplanation,
	updateLaboratoryAiExplanationConsent,
} from "../../resources/js/lib/laboratoryAiExplanation.js";

test("terminal and processing status helpers", () => {
	assert.equal(isAiExplanationTerminalStatus(AI_EXPLANATION_STATUS.READY), true);
	assert.equal(isAiExplanationTerminalStatus(AI_EXPLANATION_STATUS.PENDING), false);
	assert.equal(isAiExplanationProcessingStatus(AI_EXPLANATION_STATUS.GENERATING), true);
	assert.equal(isAiExplanationProcessingStatus(AI_EXPLANATION_STATUS.FAILED), false);
});

test("mapAiExplanationHttpError returns friendly messages", () => {
	assert.match(mapAiExplanationHttpError(401).message, /sesión/i);
	assert.match(mapAiExplanationHttpError(403).message, /acceso/i);
	assert.match(mapAiExplanationHttpError(404).message, /disponible/i);
	assert.match(mapAiExplanationHttpError(422).message, /procesar/i);
	assert.match(mapAiExplanationHttpError(429).message, /momentos/i);
	assert.match(mapAiExplanationHttpError(500).message, /problemas/i);
});

test("requestLaboratoryAiExplanation posts without clinical payload", async () => {
	const calls = [];
	const payload = {
		data: {
			status: "ready",
			explanation: "Explicación segura.",
			limitations: "Información orientativa.",
		},
	};

	globalThis.route = (name, params) => {
		assert.equal(name, "laboratory-purchases.structured-results.explanation");
		assert.equal(params.laboratory_purchase, 10);
		assert.equal(params.observation, 55);
		return `/explanation/${params.observation}`;
	};

	globalThis.fetch = async (url, options) => {
		calls.push({ url, options });
		assert.equal(options.method, "POST");
		assert.equal(options.body, JSON.stringify({}));
		return {
			ok: true,
			status: 200,
			json: async () => payload,
		};
	};

	const result = await requestLaboratoryAiExplanation(10, 55);
	assert.equal(result.status, "ready");
	assert.equal(calls.length, 1);
});

test("updateLaboratoryAiExplanationConsent posts accept action", async () => {
	globalThis.route = (name) => {
		assert.equal(name, "laboratory-purchases.ai-explanation-consent");
		return "/consent";
	};

	globalThis.fetch = async (_url, options) => {
		assert.equal(JSON.parse(options.body).action, "accept");
		return {
			ok: true,
			status: 200,
			json: async () => ({ data: { status: "accepted" } }),
		};
	};

	const result = await updateLaboratoryAiExplanationConsent(10, "accept");
	assert.equal(result.status, "accepted");
});

test("polling constants stay within product bounds", () => {
	assert.ok(AI_EXPLANATION_POLL_INTERVAL_MS >= 2000);
	assert.ok(AI_EXPLANATION_POLL_INTERVAL_MS <= 3000);
	assert.ok(AI_EXPLANATION_POLL_MAX_MS >= 30000);
	assert.ok(AI_EXPLANATION_POLL_MAX_MS <= 60000);
});
