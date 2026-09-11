export function hasValidCoordinatePair(storeOrLatitude, longitude = undefined) {
	const latitude =
		typeof storeOrLatitude === "object"
			? storeOrLatitude?.latitude
			: storeOrLatitude;
	const nextLongitude =
		typeof storeOrLatitude === "object"
			? storeOrLatitude?.longitude
			: longitude;

	if (
		latitude === null ||
		latitude === undefined ||
		latitude === "" ||
		nextLongitude === null ||
		nextLongitude === undefined ||
		nextLongitude === ""
	) {
		return false;
	}

	const numericLatitude = Number(latitude);
	const numericLongitude = Number(nextLongitude);

	return (
		Number.isFinite(numericLatitude) &&
		Number.isFinite(numericLongitude) &&
		numericLatitude >= -90 &&
		numericLatitude <= 90 &&
		numericLongitude >= -180 &&
		numericLongitude <= 180
	);
}

export function mapLocationCounts(stores = []) {
	const withCoordinates = stores.filter(hasValidCoordinatePair).length;

	return {
		total: stores.length,
		with_coordinates: withCoordinates,
		missing_coordinates: stores.length - withCoordinates,
	};
}

export function coordinateQuality(storeOrLatitude, longitude = undefined) {
	const latitude =
		typeof storeOrLatitude === "object"
			? storeOrLatitude?.latitude
			: storeOrLatitude;
	const nextLongitude =
		typeof storeOrLatitude === "object"
			? storeOrLatitude?.longitude
			: longitude;
	const hasLatitude =
		latitude !== null && latitude !== undefined && latitude !== "";
	const hasLongitude =
		nextLongitude !== null &&
		nextLongitude !== undefined &&
		nextLongitude !== "";

	if (!hasLatitude && !hasLongitude) {
		return { value: "missing_coordinates", label: "Sin coordenadas", color: "amber" };
	}

	if (!hasValidCoordinatePair(latitude, nextLongitude)) {
		return {
			value: "invalid_coordinates",
			label: "Coordenadas inválidas",
			color: "red",
		};
	}

	return { value: "ok", label: "OK", color: "green" };
}

export function findSelectedMapStore(stores = [], selectedStoreId = null) {
	if (!selectedStoreId) {
		return null;
	}

	return (
		stores.find((store) => Number(store.id) === Number(selectedStoreId)) ||
		null
	);
}
