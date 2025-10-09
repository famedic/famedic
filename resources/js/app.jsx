import "./bootstrap";
import "../css/app.css";
import { registerServiceWorker } from "./serviceworker";
import { createRoot, hydrateRoot } from "react-dom/client";
import { createInertiaApp } from "@inertiajs/react";
import { resolvePageComponent } from "laravel-vite-plugin/inertia-helpers";
import React from "react";

const appName = import.meta.env.VITE_APP_NAME || "Laravel";

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

			registerServiceWorker();
			return;
		}

		hydrateRoot(el, <App {...props} />);
		registerServiceWorker();
	},
	progress: {
		color: "#007BAD",
	},
});
