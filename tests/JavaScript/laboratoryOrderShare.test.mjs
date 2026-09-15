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
	assert.match(source, /ApplicationLogo/);
	assert.match(source, />\s*FAMEDIC\s*</);
	assert.match(source, /dark:text-white/);
	assert.match(source, /Head title=\{metaTitle\}/);
	assert.match(source, /Orden de compra de laboratorio \| FAMEDIC/);
	assert.match(source, /Consulta la información de tu orden de laboratorio, estudios solicitados, cita e indicaciones de preparación\./);
	assert.match(source, /og:title/);
	assert.match(source, /og:description/);
	assert.match(source, /og:image/);
	assert.match(source, /og:url/);
	assert.match(source, /summary_large_image/);
	assert.match(source, /\/images\/og\/famedic-og\.png/);
	assert.match(source, /buildAbsoluteUrl/);
	assert.match(source, /props\?\.ziggy\?\.location/);
	assert.match(source, /icon: Icon = null/);
	assert.match(source, /icon=\{MapPinIcon\}/);
	assert.match(source, /bg-\[#171f45\]/);
	assert.match(source, /bg-lime-300/);
	assert.match(source, /text-lime-300/);
	assert.match(source, /border-l-\[#5944b5\]/);
	assert.match(source, /¿Necesitas ayuda\? Tenemos estos canales de soporte\./);
	assert.match(source, /href="tel:8128601893"/);
	assert.match(source, /81 2860 1893/);
	assert.match(source, /WhatsAppIcon/);
	assert.match(source, /https:\/\/wa\.me\/528128601893/);
	assert.match(source, /Hola, necesito ayuda con mi orden de laboratorio Famedic\./);
	assert.match(source, /Contactar por WhatsApp/);
	assert.match(
		source,
		/\{patientAndOrderCards\}\s+\{appointmentCard\}\s+\{studiesAndSummaryCards\}\s+\{preparationCard\}\s+\{visitGuideCards\}\s+\{noAppointmentStoresCard\}/,
	);
	assert.doesNotMatch(source, /Sucursal esta pendiente|Sucursal pendiente/);
	assert.doesNotMatch(source, /Sin cita/);
	assert.doesNotMatch(source, /bg-zinc-950 px-4 py-3 text-white/);
	assert.doesNotMatch(source, /Este enlace permite consultar/);
	assert.doesNotMatch(source, /dangerouslySetInnerHTML/);
});

test("private order detail clarifies download and share actions", () => {
	const header = readFileSync("resources/js/Components/LaboratoryOrderDetail/Header.jsx", "utf8");
	const dialog = readFileSync("resources/js/Components/LaboratoryOrderDetail/ShareDialog.jsx", "utf8");

	assert.match(header, /Descargar orden/);
	assert.match(header, /Descargar orden de compra en PDF/);
	assert.match(header, /Compartir orden de compra/);
	assert.match(dialog, /Compartir orden de laboratorio/);
	assert.match(
		dialog,
		/Genera un enlace temporal para que un familiar o amigo pueda consultar tu orden de compra\./,
	);
	assert.match(dialog, /Comparte fácilmente tu orden/);
	assert.match(
		dialog,
		/Ideal para que un familiar, amigo o cuidador pueda acompañarte o ayudarte con el seguimiento\./,
	);
	assert.match(dialog, /UserGroupIcon/);
	assert.match(dialog, /LinkIcon/);
	assert.match(dialog, /Generar enlace/);
	assert.doesNotMatch(dialog, /Este enlace no muestra resultados/);
});
