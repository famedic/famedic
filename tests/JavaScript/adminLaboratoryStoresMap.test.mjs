import assert from "node:assert/strict";
import test from "node:test";

import {
	coordinateQuality,
	findSelectedMapStore,
	hasValidCoordinatePair,
	mapLocationCounts,
} from "../../resources/js/lib/adminLaboratoryStoresMap.js";

test("validates coordinate pairs before rendering map markers", () => {
	assert.equal(
		hasValidCoordinatePair({ latitude: 19.4326, longitude: -99.1332 }),
		true,
	);
	assert.equal(hasValidCoordinatePair("19.4326000", "-99.1332000"), true);
	assert.equal(
		hasValidCoordinatePair({ latitude: null, longitude: -99.1332 }),
		false,
	);
	assert.equal(
		hasValidCoordinatePair({ latitude: 91, longitude: -99.1332 }),
		false,
	);
	assert.equal(
		hasValidCoordinatePair({ latitude: 19.4326, longitude: -181 }),
		false,
	);
	assert.equal(
		hasValidCoordinatePair({ latitude: "abc", longitude: -99.1332 }),
		false,
	);
});

test("counts stores with and without usable coordinates", () => {
	const counts = mapLocationCounts([
		{ id: 1, latitude: 19.43, longitude: -99.13 },
		{ id: 2, latitude: null, longitude: -99.13 },
		{ id: 3, latitude: 91, longitude: -99.13 },
		{ id: 4, latitude: 25.68, longitude: -100.31 },
	]);

	assert.deepEqual(counts, {
		total: 4,
		with_coordinates: 2,
		missing_coordinates: 2,
	});
});

test("labels coordinate quality for UI states", () => {
	assert.deepEqual(coordinateQuality(19.4326, -99.1332), {
		value: "ok",
		label: "OK",
		color: "green",
	});
	assert.deepEqual(coordinateQuality(null, null), {
		value: "missing_coordinates",
		label: "Sin coordenadas",
		color: "amber",
	});
	assert.deepEqual(coordinateQuality(91, -99.1332), {
		value: "invalid_coordinates",
		label: "Coordenadas inválidas",
		color: "red",
	});
});

test("finds the selected map store without mutating the filtered collection", () => {
	const stores = [
		{ id: 10, name: "A" },
		{ id: 11, name: "B" },
	];

	assert.deepEqual(findSelectedMapStore(stores, "11"), { id: 11, name: "B" });
	assert.equal(findSelectedMapStore(stores, 99), null);
	assert.deepEqual(stores, [
		{ id: 10, name: "A" },
		{ id: 11, name: "B" },
	]);
});
