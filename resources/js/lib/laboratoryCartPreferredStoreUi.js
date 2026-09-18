export function formatStudyCountLabel(count) {
	const safeCount = Math.max(0, Number(count) || 0);

	if (safeCount === 1) {
		return "1 estudio";
	}

	return `${safeCount} estudios`;
}

export function hasPreferredStoreSelection(selectedStore) {
	return Boolean(selectedStore?.id || selectedStore?.name);
}

export function shouldPromptForPreferredStore(selectedStore) {
	return !hasPreferredStoreSelection(selectedStore);
}

export function preferredStoreSummaryPresentation(selectedStore) {
	if (hasPreferredStoreSelection(selectedStore)) {
		return {
			title: "Sucursal",
			primary: selectedStore.name,
			secondary: "Preferencia del paciente",
			hasSelection: true,
		};
	}

	return {
		title: "Sucursal",
		primary: "Sin seleccionar",
		secondary: "Selección opcional",
		hasSelection: false,
	};
}

export function preferredStoreCardMode(selectedStore) {
	return hasPreferredStoreSelection(selectedStore) ? "selected" : "empty";
}

export function isSelectorInitiallyVisible({
	desktopExpanded = false,
	mobileSheetOpen = false,
} = {}) {
	return Boolean(desktopExpanded || mobileSheetOpen);
}

export function shouldLoadCompatibleStores({
	selectorOpen = false,
	compatibleStoresLoaded = false,
} = {}) {
	return selectorOpen && !compatibleStoresLoaded;
}

export function createCheckoutAttemptTracker() {
	let lastCheckoutAt = 0;

	return {
		shouldProceed(cooldownMs = 2000) {
			const now = Date.now();

			if (lastCheckoutAt && now - lastCheckoutAt < cooldownMs) {
				return false;
			}

			lastCheckoutAt = now;
			return true;
		},
		reset() {
			lastCheckoutAt = 0;
		},
	};
}
