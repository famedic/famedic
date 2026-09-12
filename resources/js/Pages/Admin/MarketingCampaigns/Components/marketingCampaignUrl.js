const UTM_FIELDS = [
	"utm_source",
	"utm_medium",
	"utm_campaign",
	"utm_term",
	"utm_content",
];

function currentOrigin() {
	if (typeof window !== "undefined" && window.location?.origin) {
		return window.location.origin;
	}

	return "https://example.invalid";
}

export function marketingCampaignBaseUrl(slug, origin = currentOrigin()) {
	if (!slug) return "";
	const url = new URL(`/c/${slug}`, origin);
	return url.toString();
}

export function marketingCampaignFullUrl(link = {}, origin = currentOrigin()) {
	const baseUrl = marketingCampaignBaseUrl(link.slug, origin);

	if (!baseUrl) return "";

	const url = new URL(baseUrl);
	for (const field of UTM_FIELDS) {
		const value = link[field];
		if (value === null || value === undefined || value === "") {
			continue;
		}
		url.searchParams.set(field, String(value));
	}

	return url.toString();
}

export function marketingCampaignUtmEntries(link = {}) {
	return UTM_FIELDS.map((field) => [field, link[field] ?? ""]);
}
