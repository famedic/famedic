import assert from "node:assert/strict";
import test from "node:test";

import {
	displayStatusLabel,
	paymentHistorySummary,
} from "../../resources/js/lib/cartDrawerDisplay.js";

test("summarizes empty payment history as no records", () => {
	assert.equal(paymentHistorySummary([]), "Sin registros");
});

test("summarizes payment attempts without calling final payments attempts", () => {
	assert.equal(
		paymentHistorySummary([
			{ type: "payment_attempt", status: "declined" },
			{ type: "payment_attempt", status: "approved" },
		]),
		"2 intentos | 1 fallo | 1 aprobado",
	);
});

test("summarizes mixed payment attempt and final transaction neutrally", () => {
	assert.equal(
		paymentHistorySummary([
			{ type: "payment_attempt", status: "error" },
			{ type: "final_payment", status: "completed" },
		]),
		"2 registros | 1 fallo | 1 aprobado",
	);
});

test("keeps direct array calls compatible with drawer usage", () => {
	assert.equal(
		paymentHistorySummary([{ type: "final_payment", status: "completed" }]),
		"1 registro | 1 aprobado",
	);
});

test("normalizes visible status labels without changing raw statuses", () => {
	assert.equal(displayStatusLabel("approved"), "Aprobado");
	assert.equal(displayStatusLabel("synced"), "Sincronizado");
	assert.equal(displayStatusLabel("Pago aprobado"), "Aprobado");
});
