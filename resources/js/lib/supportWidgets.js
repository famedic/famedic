const MODAL_STATE_EVENT = "famedic:modal-state-change";
const SUPPORT_WIDGETS_HIDDEN_CLASS = "support-widgets-hidden";
const SUPPORT_WIDGET_SELECTOR = [
	'iframe[src*="salesiq"]',
	'iframe[src*="zohopublic.com"]',
	'iframe[src*="diffuser-cdn.app-us1.com"]',
	'[id*="zsiq"]',
	'[class*="zsiq"]',
	'[id*="whatsapp"]',
	'[class*="whatsapp"]',
	'[data-widget-id="06a47e35-87c0-72a7-8000-831831976ef4"]',
].join(",");

let activeModalCount = 0;
let initialized = false;

function normalizePath(pathname = "/") {
	const path = pathname || "/";
	return path !== "/" ? path.replace(/\/+$/, "") : path;
}

export function isAdminPath(pathname = "/") {
	const path = normalizePath(pathname);
	return path === "/admin" || path.startsWith("/admin/");
}

export function shouldShowSupportWidgets({
	enabled = false,
	shouldRender = false,
	pathname = "/",
	modalCount = 0,
} = {}) {
	return Boolean(enabled && shouldRender && modalCount === 0 && !isAdminPath(pathname));
}

export function supportWidgetsConfig(win = window) {
	const config = win.__FAMEDIC_SUPPORT_WIDGETS__ || {};

	return {
		enabled: Boolean(config.enabled),
		shouldRender: Boolean(config.shouldRender),
	};
}

export function setZohoSalesIQVisible(visible, win = window) {
	const salesiq = win.$zoho?.salesiq;

	salesiq?.floatbutton?.visible?.("hide");

	if (!visible) {
		salesiq?.floatwindow?.visible?.("hide");
	}
}

function fixedWidgetContainer(element, win) {
	let current = element;

	while (current && current !== win.document?.body) {
		const style = win.getComputedStyle?.(current);

		if (style?.position === "fixed") return current;

		current = current.parentElement;
	}

	return element;
}

export function setThirdPartyWidgetDomVisible(visible, win = window, doc = document) {
	doc.querySelectorAll?.(SUPPORT_WIDGET_SELECTOR).forEach((element) => {
		const target = fixedWidgetContainer(element, win);

		if (!target.dataset) return;

		if (!visible) {
			if (target.dataset.supportWidgetPreviousDisplay == null) {
				target.dataset.supportWidgetPreviousDisplay = target.style.display || "";
				target.dataset.supportWidgetPreviousVisibility = target.style.visibility || "";
				target.dataset.supportWidgetPreviousPointerEvents =
					target.style.pointerEvents || "";
			}

			target.style.display = "none";
			target.style.visibility = "hidden";
			target.style.pointerEvents = "none";
			return;
		}

		if (target.dataset.supportWidgetPreviousDisplay == null) return;

		target.style.display = target.dataset.supportWidgetPreviousDisplay;
		target.style.visibility = target.dataset.supportWidgetPreviousVisibility;
		target.style.pointerEvents = target.dataset.supportWidgetPreviousPointerEvents;

		delete target.dataset.supportWidgetPreviousDisplay;
		delete target.dataset.supportWidgetPreviousVisibility;
		delete target.dataset.supportWidgetPreviousPointerEvents;
	});
}

export function applySupportWidgetVisibility({
	win = window,
	doc = document,
	pathname = win.location?.pathname || "/",
	modalCount = activeModalCount,
} = {}) {
	const config = supportWidgetsConfig(win);
	const visible = shouldShowSupportWidgets({
		...config,
		pathname,
		modalCount,
	});

	doc.documentElement.classList.toggle(SUPPORT_WIDGETS_HIDDEN_CLASS, !visible);
	setZohoSalesIQVisible(visible, win);
	setThirdPartyWidgetDomVisible(visible, win, doc);

	return visible;
}

export function setSupportWidgetModalOpen(open, win = window) {
	activeModalCount = Math.max(0, activeModalCount + (open ? 1 : -1));
	applySupportWidgetVisibility({ win, doc: win.document, modalCount: activeModalCount });

	win.dispatchEvent(
		new CustomEvent(MODAL_STATE_EVENT, {
			detail: { openModalCount: activeModalCount },
		}),
	);

	return activeModalCount;
}

export function initSupportWidgetVisibilityController(win = window) {
	if (initialized) return;
	initialized = true;

	const sync = () => applySupportWidgetVisibility({ win, doc: win.document });

	win.addEventListener(MODAL_STATE_EVENT, (event) => {
		activeModalCount = Math.max(0, Number(event.detail?.openModalCount) || 0);
		sync();
	});
	win.addEventListener("zoho-salesiq-ready", sync);
	sync();
}

export function supportWidgetsHiddenClass() {
	return SUPPORT_WIDGETS_HIDDEN_CLASS;
}

export function resetSupportWidgetVisibilityForTests() {
	activeModalCount = 0;
	initialized = false;
}
