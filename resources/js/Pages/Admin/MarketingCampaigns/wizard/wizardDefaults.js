import { slugify } from "../Components/MarketingCampaignLinkForm";

export { slugify };

export const CAMPAIGN_STEPS = [
	{ id: "general", label: "Información general" },
	{ id: "promotion", label: "Qué promocionar" },
	{ id: "products", label: "Productos" },
	{ id: "content", label: "Contenido" },
	{ id: "channel", label: "Canal y UTMs" },
	{ id: "preview", label: "Vista previa" },
];

export const LINK_STEPS = [
	{ id: "promotion", label: "Qué promocionar" },
	{ id: "products", label: "Productos" },
	{ id: "content", label: "Contenido" },
	{ id: "channel", label: "Canal y UTMs" },
	{ id: "preview", label: "Vista previa" },
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

export function initialWizardState(mode, props = {}) {
	const campaignName = props.campaign?.name || "";
	const draft = props.initialDraft || null;

	if (draft) {
		return draft;
	}

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
