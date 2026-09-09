import "./bootstrap";
import "../css/app.css";
import { registerServiceWorker } from "./serviceworker";
import { createRoot, hydrateRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import { initActiveCampaignSiteTracking } from "./lib/activeCampaignSiteTracking";
import { initSupportWidgetVisibilityController } from "./lib/supportWidgets";
import React from "react";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

function shouldInitializeZohoSalesIQ() {
	const config = window.__FAMEDIC_ZOHO_SALESIQ__ || {};

	return Boolean(config.enabled && config.shouldRender);
}

function initZohoSalesIQIfEnabled() {
	if (!shouldInitializeZohoSalesIQ()) return;

	import("./lib/zohoSalesIQ").then(({ initZohoSalesIQTracking }) => {
		initZohoSalesIQTracking();
	});
}

createInertiaApp({
	title: (title) => `${title} - ${appName}`,
	resolve: (name) =>
		resolvePageComponent(
			`./Pages/${name}.jsx`,
			import.meta.glob("./Pages/**/*.jsx"),
		),
	setup({ el, App, props }) {
		if (import.meta.env.DEV) {
			createRoot(el).render(
				<React.StrictMode>
					<App {...props} />
				</React.StrictMode>,
			);

			initZohoSalesIQIfEnabled();
			initSupportWidgetVisibilityController();
			initActiveCampaignSiteTracking({ initialPage: props.initialPage });
			registerServiceWorker();
			return;
		}

		hydrateRoot(el, <App {...props} />);
		queueMicrotask(() => {
			initZohoSalesIQIfEnabled();
			initSupportWidgetVisibilityController();
			initActiveCampaignSiteTracking({ initialPage: props.initialPage });
		});
		registerServiceWorker();
	},
	progress: {
		color: "#007BAD",
	},
});
