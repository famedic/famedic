import assert from "node:assert/strict";
import { describe, it } from "node:test";
import {
	buildLaboratoryOrderTabs,
	formatStoreHours,
	formatStoreLocationLines,
	hasConfirmedLaboratoryStore,
	hasLaboratoryStoreTabContext,
	hasPreferredLaboratoryStore,
	normalizeLaboratoryOrderTab,
} from "../../resources/js/lib/laboratoryOrderStoreUi.js";

describe("laboratoryOrderStoreUi", () => {
	it("detects confirmed store only from appointment.laboratory_store", () => {
		assert.equal(hasConfirmedLaboratoryStore(null), false);
		assert.equal(hasConfirmedLaboratoryStore({}), false);
		assert.equal(
			hasConfirmedLaboratoryStore({ laboratory_store: { id: 1, name: "Olab" } }),
			true,
		);
	});

	it("builds Sucursal tab label when a confirmed store exists", () => {
		const tabs = buildLaboratoryOrderTabs(true);
		assert.deepEqual(tabs.map((tab) => tab.label), [
			"Resumen",
			"Sucursal",
			"Instrucciones",
		]);
	});

	it("builds Sucursales tab label when no confirmed store exists", () => {
		const tabs = buildLaboratoryOrderTabs(false);
		assert.deepEqual(tabs.map((tab) => tab.label), [
			"Resumen",
			"Sucursales",
			"Instrucciones",
		]);
	});

	it("detects preferred store only from purchase.preferred_laboratory_store", () => {
		assert.equal(hasPreferredLaboratoryStore(null), false);
		assert.equal(hasPreferredLaboratoryStore({}), false);
		assert.equal(
			hasPreferredLaboratoryStore({
				preferred_laboratory_store: { id: 1, name: "Alamos" },
			}),
			true,
		);
	});

	it("builds Sucursal tab when preferred or confirmed store exists", () => {
		const purchase = { preferred_laboratory_store: { id: 1, name: "Alamos" } };
		const appointment = { laboratory_store: { id: 2, name: "Valle Oriente" } };

		assert.equal(hasLaboratoryStoreTabContext(purchase, null), true);
		assert.equal(hasLaboratoryStoreTabContext(null, appointment), true);
		assert.equal(hasLaboratoryStoreTabContext(purchase, appointment), true);
		assert.equal(hasLaboratoryStoreTabContext(null, null), false);

		assert.deepEqual(
			buildLaboratoryOrderTabs(
				hasLaboratoryStoreTabContext(purchase, appointment),
			).map((tab) => tab.label),
			["Resumen", "Sucursal", "Instrucciones"],
		);
	});

	it("keeps preferred and confirmed store detection independent", () => {
		const purchase = { preferred_laboratory_store: { id: 1, name: "Alamos" } };
		const appointment = { laboratory_store: { id: 1, name: "Alamos" } };

		assert.equal(hasPreferredLaboratoryStore(purchase), true);
		assert.equal(hasConfirmedLaboratoryStore(appointment), true);
		assert.equal(hasPreferredLaboratoryStore({ laboratory_appointment: appointment }), false);
		assert.equal(hasConfirmedLaboratoryStore({ preferred_laboratory_store: purchase.preferred_laboratory_store }), false);
	});

	it("normalizes legacy and new tab query params", () => {
		assert.equal(normalizeLaboratoryOrderTab("patient"), "summary");
		assert.equal(normalizeLaboratoryOrderTab("resumen"), "summary");
		assert.equal(normalizeLaboratoryOrderTab("sucursales"), "store");
		assert.equal(normalizeLaboratoryOrderTab("sucursal"), "store");
		assert.equal(normalizeLaboratoryOrderTab("facturas"), "invoice");
		assert.equal(normalizeLaboratoryOrderTab("instructions"), "instructions");
	});

	it("formats store location and hours from real store fields", () => {
		const store = {
			address: "Fray Diego Altamirano 536",
			neighborhood: "San Francisco",
			postal_code: "67280",
			state: "Nuevo León",
			municipality: "Juárez",
			weekly_hours: "7:00 - 18:00",
			saturday_hours: "8:00 - 14:00",
		};

		assert.deepEqual(formatStoreLocationLines(store), [
			"Fray Diego Altamirano 536",
			"San Francisco, 67280",
			"Nuevo León, Juárez",
		]);
		assert.equal(
			formatStoreHours(store),
			"Lun–vie: 7:00 - 18:00 · Sáb: 8:00 - 14:00",
		);
	});
});
