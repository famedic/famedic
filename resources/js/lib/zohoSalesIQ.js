import { router } from "@inertiajs/react";

const WIDGET_SRC =
	"https://salesiq.zohopublic.com/widget?wc=siqa5c1962de4be78bdee6d1289a9999c2f57b865275c57f26970b8bae68fc5e5b4";

let initialized = false;

function isEnabled() {
	return !!window.__FAMEDIC_ZOHO_SALESIQ__?.enabled;
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
	if (window.$zoho?.salesiq?.visitor?.customaction) {
		callback();
		return;
	}

	window.addEventListener("zoho-salesiq-ready", callback, { once: true });
}

function setupZohoGlobals() {
	window.$zoho = window.$zoho || {};
	window.$zoho.salesiq = window.$zoho.salesiq || { ready: function () {} };

	window.$zoho.salesiq.ready = function () {
		window.$zoho.salesiq.tracking?.on?.();
	};

	window.$zoho.salesiq.afterReady = function () {
		window.dispatchEvent(new Event("zoho-salesiq-ready"));
	};
}

function loadZohoWidget() {
	if (document.getElementById("zsiqscript")) return;

	setupZohoGlobals();

	const script = document.createElement("script");
	script.id = "zsiqscript";
	script.defer = true;
	script.src = WIDGET_SRC;
	document.body.appendChild(script);
}

export function initZohoSalesIQTracking() {
	if (initialized || !isEnabled()) return;
	initialized = true;

	loadZohoWidget();
	whenZohoReady(trackZohoPageView);
	router.on("finish", () => whenZohoReady(trackZohoPageView));
}
