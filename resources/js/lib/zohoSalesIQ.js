import { router } from "@inertiajs/react";

let initialized = false;

function isZohoPresent() {
	return typeof window !== "undefined" && !!window.$zoho?.salesiq;
}

function trackZohoPageView() {
	if (!window.$zoho?.salesiq?.visitor?.customaction) return;

	const path = window.location.pathname + window.location.search;

	window.$zoho.salesiq.visitor.customaction(`Page: ${path}`);
	window.$zoho.salesiq.visitor.info?.({
		"Pagina actual": path,
		Hostname: window.location.hostname,
	});
}

function whenZohoReady(callback) {
	if (!isZohoPresent()) return;

	if (window.$zoho.salesiq.visitor?.customaction) {
		callback();
		return;
	}

	window.addEventListener("zoho-salesiq-ready", callback, { once: true });
}

export function initZohoSalesIQTracking() {
	if (initialized || !isZohoPresent()) return;
	initialized = true;

	whenZohoReady(trackZohoPageView);
	router.on("finish", () => whenZohoReady(trackZohoPageView));
}
