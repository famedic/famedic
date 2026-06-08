import { useEffect } from "react";
import { usePage } from "@inertiajs/react";

let pendingActions = [];
let readyChained = false;

function chainZohoReady() {
	if (readyChained || !window.$zoho?.salesiq) return;
	readyChained = true;

	const previousReady = window.$zoho.salesiq.ready;
	window.$zoho.salesiq.ready = function (...args) {
		if (typeof previousReady === "function") {
			previousReady.apply(this, args);
		}
		pendingActions.forEach((action) => {
			window.$zoho.salesiq.visitor?.customaction?.(action);
		});
		pendingActions = [];
	};
}

function trackZohoPageView() {
	const path = window.location.pathname + window.location.search;
	const action = `Page: ${path}`;

	if (window.$zoho?.salesiq?.visitor?.customaction) {
		window.$zoho.salesiq.visitor.customaction(action);
		return;
	}

	if (!window.$zoho?.salesiq) return;

	chainZohoReady();
	pendingActions.push(action);
}

export default function useZohoSalesIQTracking() {
	const { url } = usePage();

	useEffect(() => {
		trackZohoPageView();
	}, [url]);
}
