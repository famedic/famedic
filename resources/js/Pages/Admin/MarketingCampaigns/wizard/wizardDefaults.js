import { slugify } from "../Components/MarketingCampaignLinkForm";

export { slugify };

export const CAMPAIGN_STEPS = [
	{ id: "general", label: "Objetivo" },
	{ id: "promotion", label: "Destino" },
	{ id: "products", label: "Productos" },
	{ id: "content", label: "Contenido" },
	{ id: "images", label: "Imágenes" },
	{ id: "channel", label: "Canal y UTMs" },
	{ id: "preview", label: "Revisar y publicar" },
];

export const LINK_STEPS = [
	{ id: "promotion", label: "Destino" },
	{ id: "products", label: "Productos" },
	{ id: "content", label: "Contenido" },
	{ id: "images", label: "Imágenes" },
	{ id: "channel", label: "Canal y UTMs" },
	{ id: "preview", label: "Revisar y publicar" },
];

export function getSteps(mode) {
	return mode === "link" ? LINK_STEPS : CAMPAIGN_STEPS;
}

function brandLabel(brands, value) {
	if (!value) return "";
	if (brands?.[value]?.label) return brands[value].label;
	return String(value);
}

function categoryLabel(categories, id) {
	const match = categories.find((item) => Number(item.id) === Number(id));
	return match?.name || "";
}

function collectionLabel(collections, id) {
	const match = collections.find((item) => Number(item.id) === Number(id));
	return match?.public_title || match?.name || "";
}

function uniqueLinkSlug(baseSlug) {
	const suffix = Date.now().toString(36).slice(-5);
	return slugify(`${baseSlug || "enlace"}-${suffix}`);
}

function sourcePromotion(sourceLink) {
	const targetType = sourceLink?.target_type;
	const primaryProducts = sourceLink?.primary_products || [];

	if (targetType === "category") return "category";
	if (targetType === "product") return "product";
	if (targetType === "collection") return "existing_collection";
	if (targetType === "brand" && primaryProducts.length > 0) {
		return "multiple_products";
	}

	return targetType === "brand" ? "brand" : "";
}

function stateFromSourceLink(sourceLink, campaignName) {
	const payload = sourceLink?.target_payload || {};
	const primaryProducts = sourceLink?.primary_products || [];
	const sourceName = sourceLink?.name || campaignName || "Nuevo enlace";
	const nextName = `${sourceName} - nuevo canal`;
	const brand = payload.brand || primaryProducts[0]?.brand || "";
	const channelStep = LINK_STEPS.findIndex((step) => step.id === "channel");

	return {
		step: channelStep >= 0 ? channelStep : 0,
		promotion: sourcePromotion(sourceLink),
		brand,
		categoryId: payload.laboratory_test_category_id || "",
		product:
			sourceLink?.target_type === "product"
				? primaryProducts.find(
						(item) => Number(item.id) === Number(payload.laboratory_test_id),
					) || primaryProducts[0] || null
				: null,
		collectionId: payload.marketing_campaign_collection_id || "",
		primaryProducts,
		relatedProducts: sourceLink?.related_products || [],
		relatedCategories: sourceLink?.related_categories || [],
		galleryItems: [],
		heroPreviewUrl: sourceLink?.hero_image_preview_url || null,
		link: {
			name: nextName,
			slug: uniqueLinkSlug(sourceLink?.slug || nextName),
			status: "draft",
			target_type: sourceLink?.target_type || "brand",
			target_payload: payload,
			public_title: sourceLink?.public_title || "",
			public_subtitle: sourceLink?.public_subtitle || "",
			public_description: sourceLink?.public_description || "",
			eyebrow: sourceLink?.eyebrow || "",
			primary_cta_label: sourceLink?.primary_cta_label || "",
			secondary_cta_label: sourceLink?.secondary_cta_label || "",
			show_prices: sourceLink?.show_prices ?? true,
			show_brand_logo: sourceLink?.show_brand_logo ?? true,
			show_campaign_dates: sourceLink?.show_campaign_dates ?? false,
			landing_layout: sourceLink?.landing_layout || "default",
			landing_template: sourceLink?.landing_template || "conversion",
			editorial_eyebrow: sourceLink?.editorial_eyebrow || "",
			editorial_title: sourceLink?.editorial_title || "",
			editorial_body: sourceLink?.editorial_body || "",
			editorial_items: sourceLink?.editorial_items || [],
			hero_image_source: "none",
			hero_image_url: "",
			hero_image_alt: sourceLink?.hero_image_alt || "",
			hero_image: null,
			utm_source: "",
			utm_medium: "",
			utm_campaign: sourceLink?.utm_campaign || slugify(campaignName || sourceName),
			utm_term: "",
			utm_content: "",
			starts_at: sourceLink?.starts_at || "",
			ends_at: sourceLink?.ends_at || "",
			source_link_id: sourceLink?.id || null,
			reuse_source_media: true,
		},
		utmPreset: "",
		contentTouched: true,
		slugTouched: true,
	};
}

export function initialWizardState(mode, props = {}) {
	const campaignName = props.campaign?.name || "";
	const draft = props.initialDraft || null;

	if (draft) {
		return draft;
	}

	const sourceDefaults =
		mode === "link" && props.sourceLink
			? stateFromSourceLink(props.sourceLink, campaignName)
			: {};

	return {
		step: 0,
		campaign: {
			name: mode === "campaign" ? "" : campaignName,
			description: "",
			status: "draft",
			starts_at: "",
			ends_at: "",
		},
		promotion: "",
		brand: "",
		categoryId: "",
		product: null,
		collectionId: "",
		newCollection: {
			name: "",
			public_title: "",
			public_description: "",
			laboratory_brand: "",
			laboratory_test_ids: [],
		},
		primaryProducts: [],
		relatedProducts: [],
		relatedCategories: [],
		galleryItems: [],
		heroPreviewUrl: null,
		link: {
			name: "",
			slug: "",
			status: "draft",
			target_type: "brand",
			target_payload: {},
			public_title: "",
			public_subtitle: "",
			public_description: "",
			eyebrow: "",
			primary_cta_label: "",
			secondary_cta_label: "",
			show_prices: true,
			show_brand_logo: true,
			show_campaign_dates: false,
			landing_layout: "default",
			landing_template: "conversion",
			editorial_eyebrow: "",
			editorial_title: "",
			editorial_body: "",
			editorial_items: [],
			hero_image_source: "none",
			hero_image_url: "",
			hero_image_alt: "",
			hero_image: null,
			utm_source: "",
			utm_medium: "",
			utm_campaign: "",
			utm_term: "",
			utm_content: "",
			starts_at: "",
			ends_at: "",
		},
		utmPreset: "",
		contentTouched: false,
		slugTouched: false,
		...sourceDefaults,
	};
}

export function buildTargetPayload(state, collections = []) {
	const { promotion, brand, categoryId, product, collectionId } = state;

	switch (promotion) {
		case "brand":
		case "multiple_products":
			return { target_type: "brand", target_payload: { brand } };
		case "category":
			return {
				target_type: "category",
				target_payload: {
					brand,
					laboratory_test_category_id: Number(categoryId),
				},
			};
		case "product":
			return {
				target_type: "product",
				target_payload: {
					laboratory_test_id: Number(product?.id),
				},
			};
		case "existing_collection":
			return {
				target_type: "collection",
				target_payload: {
					marketing_campaign_collection_id: Number(collectionId),
				},
			};
		case "new_collection":
			return { target_type: "collection", target_payload: {} };
		default:
			return { target_type: "brand", target_payload: {} };
	}
}

export function buildDefaultContent(state, { brands = {}, categories = [], collections = [] } = {}) {
	const { promotion, brand, categoryId, product, collectionId, newCollection, campaign } =
		state;
	const brandName = brandLabel(brands, brand);

	let title = campaign.name || "";
	let subtitle = brandName ? `Estudios de ${brandName}` : "";
	let description = "";
	let primaryCta = "Ver estudios";
	let secondaryCta = "Conocer más";

	if (promotion === "product" && product) {
		title = product.name || title;
		subtitle = product.other_name || subtitle;
		description = product.short_description || product.description || "";
		primaryCta = "Agregar al carrito";
	} else if (promotion === "category") {
		const cat = categoryLabel(categories, categoryId);
		title = cat || title;
		description = cat ? `Explora estudios de ${cat}.` : "";
	} else if (promotion === "existing_collection") {
		title = collectionLabel(collections, collectionId) || title;
	} else if (promotion === "new_collection") {
		title = newCollection.public_title || newCollection.name || title;
		description = newCollection.public_description || "";
	} else if (promotion === "brand") {
		title = brandName ? `${brandName}` : title;
		description = brandName
			? `Conoce los estudios disponibles de ${brandName}.`
			: "";
	}

	return {
		public_title: title,
		public_subtitle: subtitle,
		public_description: description,
		eyebrow: campaign.name ? `Campaña ${campaign.name}` : "",
		primary_cta_label: primaryCta,
		secondary_cta_label: secondaryCta,
		show_prices: true,
		show_brand_logo: true,
		show_campaign_dates: false,
		landing_template: state.link.landing_template || "conversion",
		editorial_eyebrow: state.link.editorial_eyebrow || "",
		editorial_title: state.link.editorial_title || "",
		editorial_body: state.link.editorial_body || "",
		editorial_items: state.link.editorial_items || [],
	};
}

export function applySmartDefaults(state, context) {
	const contentDefaults = buildDefaultContent(state, context);
	const linkName = state.link.name || state.campaign.name || contentDefaults.public_title;
	const slug = state.slugTouched
		? state.link.slug
		: slugify(linkName || state.campaign.name);

	return {
		...state,
		link: {
			...state.link,
			name: linkName,
			slug,
			utm_campaign:
				state.link.utm_campaign ||
				slugify(state.campaign.name || linkName),
			...(state.contentTouched
				? {}
				: {
						public_title: contentDefaults.public_title,
						public_subtitle: contentDefaults.public_subtitle,
						public_description: contentDefaults.public_description,
						eyebrow: contentDefaults.eyebrow,
						primary_cta_label: contentDefaults.primary_cta_label,
						secondary_cta_label: contentDefaults.secondary_cta_label,
						show_prices: contentDefaults.show_prices,
						show_brand_logo: contentDefaults.show_brand_logo,
						show_campaign_dates: contentDefaults.show_campaign_dates,
					}),
			...buildTargetPayload(state, context.collections),
		},
	};
}

export function validateStep(stepId, state, mode) {
	const errors = {};

	if (stepId === "general") {
		if (!String(state.campaign.name || "").trim()) {
			errors["campaign.name"] = "Indica un nombre para la campaña.";
		}
		if (!state.campaign.status) {
			errors["campaign.status"] = "Selecciona un estado inicial.";
		}
	}

	if (stepId === "promotion") {
		if (!state.promotion) {
			errors.promotion = "Elige qué quieres promocionar.";
		}
	}

	if (stepId === "products") {
		const { promotion, brand, categoryId, product, collectionId, newCollection } =
			state;

		if (["brand", "category", "multiple_products"].includes(promotion) && !brand) {
			errors.brand = "Selecciona una marca.";
		}
		if (promotion === "category" && !categoryId) {
			errors.categoryId = "Selecciona una categoría.";
		}
		if (promotion === "product" && !product?.id) {
			errors.product = "Selecciona un producto.";
		}
		if (promotion === "existing_collection" && !collectionId) {
			errors.collectionId = "Selecciona una colección.";
		}
		if (promotion === "new_collection") {
			if (!String(newCollection.name || "").trim()) {
				errors["newCollection.name"] = "Indica un nombre interno.";
			}
			if (!newCollection.laboratory_brand) {
				errors["newCollection.laboratory_brand"] = "Selecciona una marca.";
			}
		}
	}

	if (stepId === "content") {
		if (!String(state.link.public_title || "").trim()) {
			errors["link.public_title"] = "Indica un título público.";
		}
	}

	if (stepId === "channel") {
		if (!String(state.link.name || "").trim()) {
			errors["link.name"] = "Indica un nombre interno para el enlace.";
		}
		if (!String(state.link.slug || "").trim()) {
			errors["link.slug"] = "Indica la dirección del enlace.";
		}
	}

	if (stepId === "preview") {
		Object.assign(errors, validateStep("channel", state, mode));
		if (mode === "campaign") {
			Object.assign(errors, validateStep("general", state, mode));
		}
		Object.assign(errors, validateStep("promotion", state, mode));
		Object.assign(errors, validateStep("products", state, mode));
		Object.assign(errors, validateStep("content", state, mode));
	}

	return errors;
}
