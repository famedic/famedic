export function registerServiceWorker() {
	if ("serviceWorker" in navigator) {
		window.addEventListener("load", () => {
			navigator.serviceWorker
				.register("/serviceworker.js")
				.then((registration) => {
					console.log("Service worker registration succesful");
				})
				.catch((error) => {
					console.log("Service worker registration failed:", error);
				});
		});
	}
}
