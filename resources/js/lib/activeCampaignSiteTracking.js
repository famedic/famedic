import { router } from "@inertiajs/react";
import { shouldTrack } from "./activeCampaignSiteTrackingRules";

const DIFFUSER_SRC = "https://diffuser-cdn.app-us1.com/diffuser/diffuser.js";
const SCRIPT_ID = "activecampaign-site-tracking-script";

let initialized = false;
let lastIdentifiedEmail = null;
let hadIdentifiedUser = false;

function getConfig() {
	return window.__FAMEDIC_ACTIVE_CAMPAIGN_SITE_TRACKING__ || {};
}

function isEnabled() {
	const config = getConfig();
	return !!config.enabled && !!config.accountId;
}

function ensureVgo(accountId) {
	if (!window.vgo) {
		window.visitorGlobalObjectAlias = "vgo";
		window.vgo = function () {
			(window.vgo.q = window.vgo.q || []).push(arguments);
		};
		window.vgo.l = new Date().getTime();
	}

	window.vgo("setAccount", accountId);
	window.vgo("setTrackByDefault", true);

	if (document.getElementById(SCRIPT_ID)) return;

	const script = document.createElement("script");
	script.id = SCRIPT_ID;
	script.src = DIFFUSER_SRC;
	script.async = true;

	const firstScript = document.getElementsByTagName("script")[0];
	firstScript.parentNode.insertBefore(script, firstScript);
}

function getTrackingEmail(page) {
	const email = page?.props?.activeCampaignSiteTracking?.email;

	if (typeof email !== "string") return null;

	const trimmed = email.trim();
	return trimmed.includes("@") ? trimmed : null;
}

function syncIdentity(page) {
	const email = getTrackingEmail(page);

	if (!email) {
		return !hadIdentifiedUser;
	}

	hadIdentifiedUser = true;

	if (email !== lastIdentifiedEmail) {
		window.vgo("setEmail", email);
		lastIdentifiedEmail = email;
	}

	return true;
}

function processCurrentPage(page) {
	if (!isEnabled()) return;
	if (!shouldTrack(window.location.href, window.location.origin)) return;
	if (!syncIdentity(page)) return;

	window.vgo("process");
}

export function initActiveCampaignSiteTracking({ initialPage } = {}) {
	if (initialized || !isEnabled()) return;
	initialized = true;

	ensureVgo(getConfig().accountId);
	processCurrentPage(initialPage);

	router.on("navigate", (event) => {
		processCurrentPage(event.detail?.page);
	});
}
