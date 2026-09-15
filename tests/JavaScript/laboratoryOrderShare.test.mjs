import assert from "node:assert/strict";
import test from "node:test";

import {
	buildEmailShareUrl,
	buildLaboratoryOrderShareMessage,
	buildWhatsAppShareUrl,
} from "../../resources/js/lib/laboratoryOrderShare.js";

test("builds a share message without patient, invoice, payment, or results data", () => {
	const url = "https://famedic.test/shared/laboratory-orders/abc123";
	const message = buildLaboratoryOrderShareMessage(url);

	assert.match(message, /FAMEDIC/);
	assert.match(message, /cita/);
	assert.match(message, /estudios/);
	assert.match(message, /instrucciones/);
	assert.match(message, new RegExp(url));
	assert.doesNotMatch(message, /factura/i);
	assert.doesNotMatch(message, /resultado/i);
	assert.doesNotMatch(message, /rfc/i);
	assert.doesNotMatch(message, /pago/i);
});

test("encodes WhatsApp and email URLs", () => {
	const url = "https://famedic.test/shared/laboratory-orders/abc123?x=1";
	const escapedUrl = url.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");

	assert.match(buildWhatsAppShareUrl(url), /^https:\/\/wa\.me\/\?text=/);
	assert.match(decodeURIComponent(buildWhatsAppShareUrl(url)), new RegExp(escapedUrl));
	assert.match(buildEmailShareUrl(url), /^mailto:\?subject=/);
	assert.match(decodeURIComponent(buildEmailShareUrl(url)), /Cita de laboratorio FAMEDIC/);
});
