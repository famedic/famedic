import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";
import {
	marketingCampaignBaseUrl,
	marketingCampaignFullUrl,
} from "../../resources/js/Pages/Admin/MarketingCampaigns/Components/marketingCampaignUrl.js";

test("marketing campaign URL helper builds complete URLs with UTMs", () => {
	const url = marketingCampaignFullUrl(
		{
			slug: "mes-mas-patrio",
			utm_source: "google",
			utm_medium: "cpc",
			utm_campaign: "mes patrio",
			utm_term: "rayos x",
			utm_content: "hero principal",
		},
		"https://famedic.com.mx",
	);

	assert.equal(
		url,
		"https://famedic.com.mx/c/mes-mas-patrio?utm_source=google&utm_medium=cpc&utm_campaign=mes+patrio&utm_term=rayos+x&utm_content=hero+principal",
	);
});

test("marketing campaign URL helper omits empty UTMs and preserves zero", () => {
	const url = marketingCampaignFullUrl(
		{
			slug: "mes-mas-patrio",
			utm_source: "0",
			utm_medium: "",
			utm_campaign: null,
			utm_term: 0,
			utm_content: undefined,
			gclid: "external",
			fbclid: "external",
		},
		"https://famedic.com.mx",
	);

	assert.equal(url, "https://famedic.com.mx/c/mes-mas-patrio?utm_source=0&utm_term=0");
	assert.equal(marketingCampaignBaseUrl("mes-mas-patrio", "https://famedic.com.mx"), "https://famedic.com.mx/c/mes-mas-patrio");
});

test("marketing campaign URL UI exposes copy, selector, parameter panel, and wizard preview", () => {
	const table = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLinksTable.jsx",
		"utf8",
	);
	const show = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Show.jsx",
		"utf8",
	);
	const wizard = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/MarketingCampaignSetupWizard.jsx",
		"utf8",
	);

	assert.match(table, /URL completa con UTMs/);
	assert.match(table, /Copiar URL completa/);
	assert.match(table, /Ver parámetros/);
	assert.match(table, /utm_source/);
	assert.match(table, /link\.full_url \|\| link\.public_url/);
	assert.match(show, /campaignLinks\.length > 1/);
	assert.match(show, /Selecciona un enlace/);
	assert.match(show, /Copiar URL con UTMs/);
	assert.match(wizard, /marketingCampaignFullUrl/);
	assert.match(wizard, /URL que compartirá Marketing/);
	assert.match(wizard, /Vista previa de la URL/);
});
