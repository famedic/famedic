import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import test from "node:test";

test("marketing campaign landing templates are modeled and migrated", () => {
	const enumFile = readFileSync("app/Enums/MarketingCampaignLandingTemplate.php", "utf8");
	const migration = readFileSync(
		"database/migrations/2026_09_11_100000_add_landing_template_to_marketing_campaign_links.php",
		"utf8",
	);

	for (const template of ["conversion", "editorial", "catalog"]) {
		assert.match(enumFile, new RegExp(`'${template}'`));
	}

	assert.match(migration, /landing_template/);
	assert.match(migration, /MarketingCampaignLandingTemplate::Conversion->value/);
});

test("admin requests validate landing template values", () => {
	const rules = readFileSync(
		"app/Http/Requests/Admin/MarketingCampaigns/MarketingCampaignLinkLandingRules.php",
		"utf8",
	);
	const setupRequest = readFileSync(
		"app/Http/Requests/Admin/MarketingCampaigns/StoreMarketingCampaignSetupRequest.php",
		"utf8",
	);

	assert.match(rules, /'landing_template' => \['nullable', Rule::enum\(MarketingCampaignLandingTemplate::class\)\]/);
	assert.match(setupRequest, /'link\.landing_template' => \['nullable', Rule::enum\(MarketingCampaignLandingTemplate::class\)\]/);
});

test("wizard and edit payloads persist the selected landing template", () => {
	const wizardSubmit = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/wizardSubmit.js",
		"utf8",
	);
	const editPage = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Links/Edit.jsx",
		"utf8",
	);

	assert.match(wizardSubmit, /landing_template: link\.landing_template \|\| "conversion"/);
	assert.match(editPage, /landing_template: form\.landing_template \|\| "conversion"/);
});

test("public landing disables actions during admin preview", () => {
	const landing = readFileSync(
		"resources/js/Pages/MarketingCampaigns/Landing.jsx",
		"utf8",
	);
	const productCard = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignProductCard.jsx",
		"utf8",
	);
	const productGrid = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignProductGrid.jsx",
		"utf8",
	);

	assert.match(landing, /const isAdminPreview = Boolean\(preview\?\.admin\)/);
	assert.match(landing, /const canUseActions = !isAdminPreview/);
	assert.match(landing, /acciones, cookies y tracking están/);
	assert.match(productCard, /getActionButtonProps\(product\.detail_url/);
	assert.match(productGrid, /actionButtonProps=\{actionButtonProps\}/);
});

test("public landing templates are selected through a shared registry", () => {
	const registry = readFileSync(
		"resources/js/Pages/MarketingCampaigns/templates/index.js",
		"utf8",
	);
	const landing = readFileSync(
		"resources/js/Pages/MarketingCampaigns/Landing.jsx",
		"utf8",
	);
	const preview = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLandingPreview.jsx",
		"utf8",
	);

	for (const template of ["conversion", "editorial", "catalog"]) {
		assert.match(registry, new RegExp(`${template}:`));
	}

	assert.match(registry, /resolveLandingTemplate/);
	assert.match(landing, /const Template = resolveLandingTemplate\(content\?\.landing_template\)/);
	assert.match(preview, /const Template = resolveLandingTemplate\(content\.landing_template \|\| landingTemplate\)/);
});

test("admin selector communicates recommended template use cases", () => {
	const selector = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLandingTemplateSelector.jsx",
		"utf8",
	);
	const wizard = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/MarketingCampaignSetupWizard.jsx",
		"utf8",
	);
	const editPage = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLinkForm.jsx",
		"utf8",
	);

	assert.match(selector, /Conversión directa/);
	assert.match(selector, /Editorial de salud/);
	assert.match(selector, /Catálogo premium/);
	assert.match(selector, /Recomendada/);
	assert.match(selector, /aria-pressed/);
	assert.match(wizard, /MarketingCampaignLandingTemplateSelector/);
	assert.match(editPage, /MarketingCampaignLandingTemplateSelector/);
});

test("editorial template stores curated plain text content fields", () => {
	const rules = readFileSync(
		"app/Http/Requests/Admin/MarketingCampaigns/MarketingCampaignLinkLandingRules.php",
		"utf8",
	);
	const viewModel = readFileSync(
		"app/Services/Marketing/MarketingCampaignLandingViewModelFactory.php",
		"utf8",
	);

	assert.match(rules, /editorial_items'\s*=>\s*\['nullable', 'array', 'max:4'\]/);
	assert.match(rules, /editorial_items\.\*\.icon'\s*=>\s*\['nullable', 'string', Rule::in/);
	assert.match(viewModel, /editorialItems/);
	assert.match(viewModel, /allowedIcons/);
});
