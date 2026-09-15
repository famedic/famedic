import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
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

test("public order page keeps study indications behind an accessible accordion", () => {
	const source = readFileSync("resources/js/Pages/Shared/LaboratoryOrder.jsx", "utf8");

	assert.match(source, /aria-expanded=\{isOpen\}/);
	assert.match(source, /aria-controls=\{panelId\}/);
	assert.match(source, /openIndexes/);
	assert.match(source, /toggleIndex/);
	assert.match(source, /indicationIndexes\.length >= 10/);
	assert.match(source, /Expandir todas/);
	assert.match(source, /Contraer todas/);
	assert.match(source, /whitespace-pre-line/);
	assert.match(source, /Orden de compra de laboratorio/);
	assert.match(source, /Tus estudios no requieren cita previa/);
	assert.match(source, /No requiere cita/);
	assert.match(source, /Ver indicaciones/);
	assert.match(source, /Ocultar indicaciones/);
	assert.match(source, /Ver todas las \{brand\.stores_count\} sucursales/);
	assert.match(source, /FeaturedStoreCard/);
	assert.match(source, /flex-col gap-2 sm:flex-row sm:items-start sm:justify-between/);
	assert.match(source, /Folio/);
	assert.match(source, /Consecutivo/);
	assert.match(source, /Fecha de nacimiento/);
	assert.match(source, /Mismo paciente/);
	assert.match(source, /Para acudir a otra sucursal, confirma primero el cambio de cita/);
	assert.match(source, /Tu cita está programada en esta sucursal/);
	assert.match(source, /Paciente y titular/);
	assert.match(source, /Datos de tu orden/);
	assert.doesNotMatch(source, /Sucursal esta pendiente|Sucursal pendiente/);
	assert.doesNotMatch(source, /Sin cita/);
	assert.doesNotMatch(source, /bg-zinc-950 px-4 py-3 text-white/);
	assert.doesNotMatch(source, /Este enlace permite consultar/);
	assert.doesNotMatch(source, /dangerouslySetInnerHTML/);
});
