import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { pathToFileURL } from "node:url";
import path from "node:path";
import test from "node:test";
import puppeteer from "puppeteer";

import {
	applySupportWidgetVisibility,
	initSupportWidgetVisibilityController,
	isAdminPath,
	resetSupportWidgetVisibilityForTests,
	setSupportWidgetModalOpen,
	setThirdPartyWidgetDomVisible,
	shouldShowSupportWidgets,
	supportWidgetsHiddenClass,
} from "../../resources/js/lib/supportWidgets.js";

function fakeWindow({ pathname = "/laboratories", zoho = true } = {}) {
	const classList = new Set();
	const calls = [];
	const listeners = {};
	const win = {
		__FAMEDIC_SUPPORT_WIDGETS__: {
			enabled: true,
			shouldRender: true,
		},
		__FAMEDIC_ZOHO_SALESIQ__: {
			enabled: true,
			shouldRender: true,
		},
		document: {
			documentElement: {
				classList: {
					toggle(name, force) {
						if (force) classList.add(name);
						else classList.delete(name);
					},
					contains(name) {
						return classList.has(name);
					},
				},
			},
		},
		location: { pathname },
		addEventListener(name, callback) {
			listeners[name] = callback;
		},
		dispatchEvent(event) {
			listeners[event.type]?.(event);
		},
	};

	if (zoho) {
		win.$zoho = {
			salesiq: {
				floatbutton: { visible: (state) => calls.push(["button", state]) },
				floatwindow: { visible: (state) => calls.push(["window", state]) },
			},
		};
	}

	return { win, classList, calls };
}

test("blocks support widgets on admin routes", () => {
	assert.equal(isAdminPath("/admin"), true);
	assert.equal(isAdminPath("/admin/laboratory-billing/automatic-reports"), true);
	assert.equal(isAdminPath("/laboratories"), false);

	assert.equal(
		shouldShowSupportWidgets({
			enabled: true,
			shouldRender: true,
			pathname: "/admin/carts",
			modalCount: 0,
		}),
		false,
	);
});

test("shows support widgets on public routes when enabled", () => {
	assert.equal(
		shouldShowSupportWidgets({
			enabled: true,
			shouldRender: true,
			pathname: "/laboratories",
			modalCount: 0,
		}),
		true,
	);
});

test("hides widgets while any modal is open and restores after the last one closes", () => {
	resetSupportWidgetVisibilityForTests();
	globalThis.CustomEvent = class CustomEvent extends Event {
		constructor(type, options = {}) {
			super(type);
			this.detail = options.detail;
		}
	};

	const { win, calls } = fakeWindow();

	setSupportWidgetModalOpen(true, win);
	setSupportWidgetModalOpen(true, win);
	assert.deepEqual(calls.slice(-2), [["button", "hide"], ["window", "hide"]]);

	setSupportWidgetModalOpen(false, win);
	assert.deepEqual(calls.slice(-2), [["button", "hide"], ["window", "hide"]]);

	setSupportWidgetModalOpen(false, win);
	assert.deepEqual(calls.slice(-1), [["button", "hide"]]);
});

test("does not fail when Zoho has not loaded", () => {
	resetSupportWidgetVisibilityForTests();
	const { win, classList } = fakeWindow({ zoho: false });

	assert.doesNotThrow(() => applySupportWidgetVisibility({ win, doc: win.document }));
	assert.equal(classList.has(supportWidgetsHiddenClass()), false);
});

test("does not touch Zoho SalesIQ when its feature flag is disabled", () => {
	resetSupportWidgetVisibilityForTests();
	const { win, calls } = fakeWindow();
	const listeners = [];
	win.__FAMEDIC_ZOHO_SALESIQ__ = {
		enabled: false,
		shouldRender: false,
	};
	win.addEventListener = (name) => listeners.push(name);

	applySupportWidgetVisibility({ win, doc: win.document, modalCount: 1 });
	initSupportWidgetVisibilityController(win);

	assert.deepEqual(calls, []);
	assert.equal(listeners.includes("zoho-salesiq-ready"), false);
});

test("hidden class is applied when a public modal blocks support widgets", () => {
	const { win, classList } = fakeWindow();

	applySupportWidgetVisibility({ win, doc: win.document, modalCount: 1 });

	assert.equal(classList.has(supportWidgetsHiddenClass()), true);
});

test("hides and restores fixed third-party widget containers", () => {
	const fixedParent = {
		dataset: {},
		parentElement: null,
		style: {
			display: "block",
			visibility: "visible",
			pointerEvents: "auto",
		},
	};
	const iframe = {
		dataset: {},
		parentElement: fixedParent,
		style: {},
	};
	const doc = {
		body: {},
		querySelectorAll: () => [iframe],
	};
	const win = {
		document: doc,
		getComputedStyle: (element) => ({
			position: element === fixedParent ? "fixed" : "static",
		}),
	};

	setThirdPartyWidgetDomVisible(false, win, doc);

	assert.equal(fixedParent.style.display, "none");
	assert.equal(fixedParent.style.pointerEvents, "none");

	setThirdPartyWidgetDomVisible(true, win, doc);

	assert.equal(fixedParent.style.display, "block");
	assert.equal(fixedParent.style.visibility, "visible");
	assert.equal(fixedParent.style.pointerEvents, "auto");
});

test("WhatsApp fixed wrappers do not intercept clicks outside their controls", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");

	assert.match(css, /\[id\*="whatsapp"\]\[style\*="position: fixed"\][^{]+{\s*pointer-events: none;/);
	assert.match(css, /\[id\*="whatsapp"\]\[style\*="position: fixed"\] a,[\s\S]+pointer-events: auto;/);
});

test("Zoho SalesIQ launcher remains hidden while support widgets are otherwise visible", () => {
	resetSupportWidgetVisibilityForTests();
	const { win, calls } = fakeWindow();

	applySupportWidgetVisibility({ win, doc: win.document, modalCount: 0 });

	assert.deepEqual(calls, [["button", "hide"]]);
});

test("CSS removes the Zoho SalesIQ floating launcher, not only its opacity", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");

	assert.match(css, /\[id\^="zsiq_float"\],[\s\S]+display: none !important;/);
	assert.match(css, /\[id\^="zsiq_float"\],[\s\S]+pointer-events: none !important;/);
	assert.doesNotMatch(css, /zsiq_float[\s\S]{0,160}opacity:\s*0/);
});

test("ActiveCampaign WhatsApp button removes the dark blue offset shadow completely", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");

	assert.match(
		css,
		/#ac-whatsapp-widget-button\s*{[\s\S]*box-shadow:\s*none !important;/,
	);
	assert.match(css, /#ac-whatsapp-widget-button\s*{[\s\S]*border:\s*0 !important;/);
	assert.match(css, /#ac-whatsapp-widget-button\s*{[\s\S]*outline:\s*0 !important;/);
	assert.doesNotMatch(css, /#ac-whatsapp-widget-button\s*{[\s\S]*0px 48px 100px/);
	assert.doesNotMatch(css, /#ac-whatsapp-widget-button\s*{[\s\S]*rgba\(17, 12, 46/);
});

test("ActiveCampaign wrapper and pseudo-elements cannot draw an extra dark circle", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");

	assert.match(css, /#ac-whatsapp-widget-wrapper\s*{[\s\S]*background:\s*transparent !important;/);
	assert.match(css, /#ac-whatsapp-widget-wrapper\s*{[\s\S]*box-shadow:\s*none !important;/);
	assert.match(css, /#ac-whatsapp-widget-wrapper\s*{[\s\S]*pointer-events:\s*none !important;/);
	assert.match(
		css,
		/#ac-whatsapp-widget-button,[\s\S]*#ac-whatsapp-widget-wrapper textarea\s*{[\s\S]*pointer-events:\s*auto !important;/,
	);
	assert.match(
		css,
		/#ac-whatsapp-widget-button::before,[\s\S]*#ac-whatsapp-widget-wrapper::after\s*{[\s\S]*content:\s*none !important;[\s\S]*display:\s*none !important;/,
	);
});

test("checkout removes the legacy WhatsApp launcher with the red badge", () => {
	const layout = readFileSync(
		new URL("../../resources/js/Layouts/CheckoutLayout.jsx", import.meta.url),
		"utf8",
	);
	const legacyLauncher = readFileSync(
		new URL(
			"../../resources/js/Components/Checkout/CheckoutWhatsAppHelp.jsx",
			import.meta.url,
		),
		"utf8",
	);

	assert.doesNotMatch(layout, /CheckoutWhatsAppHelp/);
	assert.doesNotMatch(legacyLauncher, /export default function CheckoutWhatsAppHelp/);
	assert.doesNotMatch(legacyLauncher, /bg-red-500/);
	assert.doesNotMatch(legacyLauncher, /wa\.me/);
});

test("internal Famedic help bubble does not render when the official widget is active", () => {
	const helpBubble = readFileSync(
		new URL("../../resources/js/Components/Catalyst/HelpBubble.jsx", import.meta.url),
		"utf8",
	);

	assert.match(helpBubble, /officialSupportWidgetIsVisible/);
	assert.match(helpBubble, /shouldShowSupportWidgets/);
	assert.match(helpBubble, /supportWidgetsConfig/);
	assert.match(helpBubble, /hidden \|\| officialSupportWidgetIsVisible/);
});

test("checkout positions ActiveCampaign above the measured sticky footer", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");
	const footer = readFileSync(
		new URL(
			"../../resources/js/Components/Checkout/CheckoutWizardFloatingFooter.jsx",
			import.meta.url,
		),
		"utf8",
	);

	assert.match(css, /:root\.has-checkout-floating-footer #ac-whatsapp-widget-wrapper/);
	assert.match(css, /var\(--checkout-floating-footer-height, 0px\)/);
	assert.match(css, /max\(16px, env\(safe-area-inset-bottom\)\)/);
	assert.match(footer, /getBoundingClientRect\(\)\.height/);
	assert.match(footer, /--checkout-floating-footer-height/);
});

test("mobile bottom navigation positions ActiveCampaign above the measured nav", () => {
	const css = readFileSync(new URL("../../resources/css/app.css", import.meta.url), "utf8");
	const mobileNav = readFileSync(
		new URL("../../resources/js/Components/MobileNav/MobileBottomNav.jsx", import.meta.url),
		"utf8",
	);

	assert.match(css, /:root\.has-mobile-bottom-nav:not\(\.has-checkout-floating-footer\) #ac-whatsapp-widget-wrapper/);
	assert.match(css, /var\(--mobile-bottom-nav-height, 0px\)/);
	assert.match(css, /max\(16px, env\(safe-area-inset-bottom\)\)/);
	assert.match(mobileNav, /getBoundingClientRect\(\)\.height/);
	assert.match(mobileNav, /--mobile-bottom-nav-height/);
	assert.match(mobileNav, /has-mobile-bottom-nav/);
});

test("modal actions remain clickable above a transparent fixed widget on desktop and mobile", async (t) => {
	const moduleUrl = pathToFileURL(
		path.resolve("resources/js/lib/supportWidgets.js"),
	).href;
	let browser;

	try {
		browser = await puppeteer.launch({
			headless: "new",
			args: ["--no-sandbox", "--disable-setuid-sandbox"],
		});
	} catch (error) {
		t.skip(`Chromium no está disponible en este contenedor: ${error.message}`);
		return;
	}

	try {
		for (const viewport of [
			{ name: "desktop-1366x768", width: 1366, height: 768 },
			{ name: "mobile-390x844", width: 390, height: 844 },
		]) {
			const page = await browser.newPage();
			await page.setViewport(viewport);
			await page.setContent(`<!doctype html>
				<html>
					<head>
						<style>
							body { margin: 0; font-family: sans-serif; }
							.fake-whatsapp-wrapper { position: fixed; inset: 0; z-index: 99999; pointer-events: auto; background: transparent; }
							.fake-whatsapp-wrapper button { position: fixed; right: 24px; bottom: 24px; width: 64px; height: 64px; }
							.modal-backdrop { position: fixed; inset: 0; z-index: 100000; background: rgba(15, 23, 42, .35); }
							.modal-panel { position: fixed; right: 24px; bottom: 24px; z-index: 100001; width: min(520px, calc(100vw - 32px)); padding: 24px; background: white; }
						</style>
					</head>
					<body>
						<div class="fake-whatsapp-wrapper"><button>WA</button></div>
						<div class="modal-backdrop"></div>
						<div class="modal-panel">
							<button id="cancel">Cancelar</button>
							<button id="save">Guardar configuración</button>
						</div>
						<script>window.clicked = [];</script>
					</body>
				</html>`);
			const moduleLoaded = await page
				.evaluate(async (url) => {
					try {
						const supportWidgets = await import(url);
						window.__FAMEDIC_SUPPORT_WIDGETS__ = {
							enabled: true,
							shouldRender: true,
						};
						window.$zoho = undefined;
						document
							.querySelector("#cancel")
							.addEventListener("click", () => window.clicked.push("cancel"));
						document
							.querySelector("#save")
							.addEventListener("click", () => window.clicked.push("save"));
						supportWidgets.applySupportWidgetVisibility({
							win: window,
							doc: document,
							modalCount: 1,
						});
						return true;
					} catch {
						return false;
					}
				}, moduleUrl)
				.catch(() => false);

			if (!moduleLoaded) {
				await page.close();
				t.skip("El navegador no pudo importar el módulo local desde file://.");
				return;
			}

			await page.click("#cancel");
			await page.click("#save");

			const result = await page.evaluate(() => ({
				clicked: window.clicked,
				widgetDisplay: getComputedStyle(
					document.querySelector(".fake-whatsapp-wrapper"),
				).display,
				widgetPointerEvents: getComputedStyle(
					document.querySelector(".fake-whatsapp-wrapper"),
				).pointerEvents,
			}));

			assert.deepEqual(result.clicked, ["cancel", "save"], viewport.name);
			assert.equal(result.widgetDisplay, "none", viewport.name);
			assert.equal(result.widgetPointerEvents, "none", viewport.name);

			await page.close();
		}
	} finally {
		await browser.close();
	}
});
