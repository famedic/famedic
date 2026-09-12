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

test("public campaign product cards can remove studies already in cart", () => {
	const landing = readFileSync(
		"resources/js/Pages/MarketingCampaigns/Landing.jsx",
		"utf8",
	);
	const productCard = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignProductCard.jsx",
		"utf8",
	);

	assert.match(landing, /item\.laboratory_test\?\.id/);
	assert.match(landing, /findProductCartItem/);
	assert.match(landing, /route\("laboratory-cart-items\.destroy"/);
	assert.match(landing, /onRemove: handleRemoveFromCart/);
	assert.match(productCard, /Quitar del carrito/);
	assert.match(productCard, /onClick=\{\(\) => onRemove\?\.\(product\)\}/);
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

test("public campaign templates use distinct structural variants", () => {
	const landing = readFileSync(
		"resources/js/Pages/MarketingCampaigns/Landing.jsx",
		"utf8",
	);
	const hero = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignHero.jsx",
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
	const editorial = readFileSync(
		"resources/js/Pages/MarketingCampaigns/templates/EditorialLandingTemplate.jsx",
		"utf8",
	);
	const conversion = readFileSync(
		"resources/js/Pages/MarketingCampaigns/templates/ConversionLandingTemplate.jsx",
		"utf8",
	);
	const catalog = readFileSync(
		"resources/js/Pages/MarketingCampaigns/templates/CatalogLandingTemplate.jsx",
		"utf8",
	);

	assert.doesNotMatch(landing, /function GallerySection/);
	assert.match(hero, /variant === "editorial"/);
	assert.match(hero, /absolute inset-0 bg-gradient-to-r from-famedic-darker/);
	assert.match(hero, /w-\[46%\]/);
	assert.match(hero, /text-white/);
	assert.match(hero, /Campaña permanente/);
	assert.match(hero, /restantes/);
	assert.match(hero, /border-white\/70 bg-white\/12/);
	assert.match(productCard, /variant === "editorial"/);
	assert.match(productCard, /flex h-full min-h-0 flex-col/);
	assert.match(productCard, /className="aspect-\[4\/3\]"/);
	assert.match(productCard, /Agregar al carrito/);
	assert.match(productCard, /productCategoryIcon/);
	assert.match(productCard, /from-sky-50 via-white to-slate-50/);
	assert.match(productCard, /text-sky-800/);
	assert.doesNotMatch(productCard, /slice\(0, 1\)\.toUpperCase/);
	assert.match(productGrid, /editorial: "grid gap-5 md:grid-cols-2 lg:grid-cols-3"/);
	assert.match(productGrid, /catalog: "grid gap-5 md:grid-cols-2 xl:grid-cols-3"/);
	assert.match(editorial, /content\?\.image_roles\?\.editorial_secondary/);
	assert.match(editorial, /content\.image_roles\.gallery/);
	assert.match(conversion, /limit=\{3\}/);
	assert.match(catalog, /lg:grid-cols-\[260px_1fr\]/);
	assert.match(catalog, /variant="catalog"/);
	assert.match(catalog, /className="fixed inset-0 z-50/);
});

test("campaign landing view model exposes deterministic image roles", () => {
	const viewModel = readFileSync(
		"app/Services/Marketing/MarketingCampaignLandingViewModelFactory.php",
		"utf8",
	);

	assert.match(viewModel, /\$galleryImages = \$this->galleryImages\(\$link\)/);
	assert.match(viewModel, /'image_roles' => \[/);
	assert.match(viewModel, /'hero' => \$link->resolvedHeroImageUrl\(\)/);
	assert.match(viewModel, /'editorial_secondary' => \$galleryImages\[0\] \?\? null/);
	assert.match(viewModel, /array_slice\(\$galleryImages, 1\)/);
});

test("live preview keeps related product public prices instead of falling back to zero", () => {
	const controller = readFileSync(
		"app/Http/Controllers/Admin/MarketingCampaignLinkController.php",
		"utf8",
	);
	const preview = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLandingPreview.jsx",
		"utf8",
	);

	assert.match(
		controller,
		/primaryLandingProducts\.laboratoryTest:id,name,other_name,brand,public_price_cents,famedic_price_cents/,
	);
	assert.match(
		controller,
		/relatedLandingProducts\.laboratoryTest:id,name,other_name,brand,public_price_cents,famedic_price_cents/,
	);
	assert.match(controller, /'public_price_cents' => \(int\) \$test->public_price_cents/);
	assert.match(preview, /const publicPriceCents = product\.public_price_cents \?\? famedicPriceCents/);
	assert.match(preview, /formatCents\(publicPriceCents\)/);
	assert.doesNotMatch(preview, /public_price_cents:\s*product\.famedic_price_cents \?\? 0/);
	assert.doesNotMatch(preview, /formatted_public_price:\s*product\.price_label \|\| "\$0\.00 MXN"/);
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

test("editorial landing visual QA rules remain explicit", () => {
	const logo = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignBrandLogo.jsx",
		"utf8",
	);
	const editorialSection = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignEditorialSection.jsx",
		"utf8",
	);
	const trustStrip = readFileSync(
		"resources/js/Pages/MarketingCampaigns/components/CampaignTrustStrip.jsx",
		"utf8",
	);
	const fields = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignEditorialFields.jsx",
		"utf8",
	);
	const dates = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignDateRangeFields.jsx",
		"utf8",
	);

	assert.match(logo, /h-24 max-w-44 sm:h-28 sm:max-w-52/);
	assert.match(logo, /framed = true/);
	assert.match(logo, /bg-white px-3 py-2 ring-1 ring-white\/80/);
	assert.match(editorialSection, /lg:items-start/);
	assert.match(editorialSection, /lg:h-\[540px\]/);
	assert.match(editorialSection, /size-12/);
	assert.match(editorialSection, /bottom-5 left-1\/2/);
	assert.match(editorialSection, /text-center/);
	assert.match(editorialSection, /bg-white\/94/);
	assert.match(editorialSection, /bg-sky-50/);
	assert.match(trustStrip, /bg-slate-950/);
	assert.match(trustStrip, /text-sky-200/);
	assert.match(trustStrip, /size-12/);
	assert.match(fields, /hasEditorialPlaceholderContent/);
	assert.match(fields, /t\[ií\]tulo de prueba/);
	assert.match(dates, /Sin expiración/);
});

test("admin preview uses logical desktop and mobile viewports", () => {
	const preview = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignLandingPreview.jsx",
		"utf8",
	);

	assert.match(preview, /desktop:\s*\{\s*label:\s*"Desktop",\s*width:\s*1440,\s*height:\s*900/);
	assert.match(preview, /mobile:\s*\{\s*label:\s*"Mobile",\s*width:\s*390,\s*height:\s*844/);
	assert.match(preview, /ResizeObserver/);
	assert.match(preview, /Abrir grande/);
	assert.match(preview, /Vista previa oculta/);
	assert.match(preview, /function PreviewViewportFrame/);
	assert.match(preview, /<iframe/);
	assert.match(preview, /createPortal/);
	assert.match(preview, /copyPreviewStyles/);
	assert.match(preview, /max-w-\[1280px\]/);
	assert.match(preview, /fullscreenScale/);
	assert.match(preview, /fullscreenHostRef/);
	assert.match(preview, /transform:\s*`scale\(\$\{scale\}\)`/);
});

test("campaign wizard separates destination, content, images, and final review", () => {
	const defaults = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/wizardDefaults.js",
		"utf8",
	);
	const wizard = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/wizard/MarketingCampaignSetupWizard.jsx",
		"utf8",
	);

	assert.match(defaults, /id: "promotion", label: "Destino"/);
	assert.match(defaults, /id: "images", label: "Imágenes"/);
	assert.match(defaults, /id: "preview", label: "Revisar y publicar"/);
	assert.match(wizard, /const renderImagesStep = \(\) =>/);
	assert.match(wizard, /images: renderImagesStep/);
	assert.match(wizard, /MarketingCampaignAiContentAssistant/);
});

test("campaign detail page exposes section tabs without changing attribution widgets", () => {
	const show = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Show.jsx",
		"utf8",
	);

	for (const label of ["Resumen", "Rendimiento", "Enlaces", "Colecciones"]) {
		assert.match(show, new RegExp(`label: "${label}"`));
	}

	assert.match(show, /activeTabFromLocation/);
	assert.match(show, /window\.history\.pushState/);
	assert.match(show, /function DashboardFilters/);
	assert.match(show, /MarketingCampaignLinksTable/);
	assert.match(show, /MarketingCampaignCollectionsTable/);
});

test("campaign attributed users admin tab exposes filters export and privacy states", () => {
	const show = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Show.jsx",
		"utf8",
	);
	const table = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignAttributedUsersTable.jsx",
		"utf8",
	);
	const presenter = readFileSync(
		"app/Services/Marketing/MarketingCampaignAttributedUsersPresenter.php",
		"utf8",
	);
	const exportController = readFileSync(
		"app/Http/Controllers/Admin/MarketingCampaignAttributedUsersExportController.php",
		"utf8",
	);

	assert.match(show, /id: "attributed-users", label: "Usuarios atribuidos"/);
	assert.match(show, /MarketingCampaignAttributedUsersTable/);
	assert.match(table, /attributed_from/);
	assert.match(table, /attributed_link_id/);
	assert.match(table, /attributed_utm_source/);
	assert.match(table, /attributed_utm_medium/);
	assert.match(table, /registered_without_purchase/);
	assert.match(table, /admin\.marketing-campaigns\.attributed-users\.export/);
	assert.match(table, /PII oculta/);
	assert.match(table, /can_view_pii/);
	assert.match(presenter, /marketing_campaign_attributions as attributions/);
	assert.match(presenter, /leftJoinSub\(\$this->conversionSummary/);
	assert.match(presenter, /first_touch/);
	assert.match(presenter, /last_touch/);
	assert.match(exportController, /marketing_campaign_attributed_users_csv_exported/);
	assert.doesNotMatch(table, /visitor_token|gclid|fbclid|cookie|hash/i);
	assert.doesNotMatch(presenter, /visitor_token_hash as|gclid as|fbclid as/i);
});

test("contextual help and AI assistants are explicit review-only UI", () => {
	const help = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignFieldHelp.jsx",
		"utf8",
	);
	const hero = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignHeroImageFields.jsx",
		"utf8",
	);
	const gallery = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignGalleryFields.jsx",
		"utf8",
	);
	const contentAssistant = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignAiContentAssistant.jsx",
		"utf8",
	);
	const collectionAssistant = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignAiCollectionAssistant.jsx",
		"utf8",
	);

	assert.match(help, /role="tooltip"/);
	assert.match(hero, /MarketingCampaignFieldHelp/);
	assert.match(gallery, /MarketingCampaignFieldHelp/);
	assert.match(contentAssistant, /admin\.marketing-campaigns\.ai\.landing-content/);
	assert.match(contentAssistant, /Aplicar campos vacíos/);
	assert.match(contentAssistant, /<Field>\s*<Label>Objetivo<\/Label>/);
	assert.match(contentAssistant, /<Field>\s*<Label>Tono<\/Label>/);
	assert.match(contentAssistant, /readJson\(response\)/);
	assert.match(contentAssistant, /Reintentar/);
	assert.doesNotMatch(contentAssistant, /router\.post/);
	assert.match(collectionAssistant, /admin\.marketing-campaigns\.ai\.collection/);
	assert.match(collectionAssistant, /La IA elige únicamente entre los estudios/);
	assert.match(collectionAssistant, /<Field>\s*<Label>Enfoque<\/Label>/);
	assert.match(collectionAssistant, /readJson\(response\)/);
	assert.match(collectionAssistant, /Reintentar/);
	assert.doesNotMatch(collectionAssistant, /router\.post/);
});

test("AI assistant buttons do not submit the parent campaign form", () => {
	const contentAssistant = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignAiContentAssistant.jsx",
		"utf8",
	);
	const collectionAssistant = readFileSync(
		"resources/js/Pages/Admin/MarketingCampaigns/Components/MarketingCampaignAiCollectionAssistant.jsx",
		"utf8",
	);
	const buttonTags = [...contentAssistant.matchAll(/<Button\b[\s\S]*?>/g)]
		.concat([...collectionAssistant.matchAll(/<Button\b[\s\S]*?>/g)])
		.map((match) => match[0]);

	assert.ok(buttonTags.length > 0);
	for (const buttonTag of buttonTags) {
		assert.match(buttonTag, /type="button"/);
	}
});
