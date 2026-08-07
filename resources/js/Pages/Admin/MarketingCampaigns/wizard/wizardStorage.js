const FILE_KEYS = new Set([
	"hero_image",
	"heroUpload",
	"gallery_uploads",
	"file",
]);

function stripFiles(value) {
	if (value instanceof File) {
		return null;
	}
	if (Array.isArray(value)) {
		return value
			.map((item) => stripFiles(item))
			.filter((item) => item !== null);
	}
	if (value && typeof value === "object") {
		return Object.fromEntries(
			Object.entries(value)
				.filter(([key]) => !FILE_KEYS.has(key))
				.map(([key, nested]) => [key, stripFiles(nested)])
				.filter(([, nested]) => nested !== null),
		);
	}
	return value;
}

export function wizardStorageKey(mode, campaignId = null) {
	if (mode === "link" && campaignId) {
		return `mc-wizard-link-${campaignId}`;
	}
	return "mc-wizard-campaign";
}

export function loadDraft(mode, campaignId = null) {
	if (typeof window === "undefined") {
		return null;
	}
	try {
		const raw = window.sessionStorage.getItem(
			wizardStorageKey(mode, campaignId),
		);
		if (!raw) return null;
		return JSON.parse(raw);
	} catch {
		return null;
	}
}

export function saveDraft(mode, state, campaignId = null) {
	if (typeof window === "undefined") {
		return;
	}
	try {
		window.sessionStorage.setItem(
			wizardStorageKey(mode, campaignId),
			JSON.stringify(stripFiles(state)),
		);
	} catch {
		// sessionStorage lleno o bloqueado
	}
}

export function clearDraft(mode, campaignId = null) {
	if (typeof window === "undefined") {
		return;
	}
	window.sessionStorage.removeItem(wizardStorageKey(mode, campaignId));
}
