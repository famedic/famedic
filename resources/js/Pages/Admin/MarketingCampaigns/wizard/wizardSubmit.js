import { fromDatetimeLocalValue } from "../Components/MarketingCampaignDateRangeFields";
import {
	buildGalleryPayload,
	buildProductImagePayload,
} from "../Components/marketingCampaignLinkBrand";
import { applySmartDefaults } from "./wizardDefaults";

function nullIfEmpty(value) {
	return value === "" || value === undefined ? null : value;
}

export function buildLinkPayload(state, context) {
	const prepared = applySmartDefaults(state, context);
	const link = prepared.link;
	const galleryPayload = buildGalleryPayload(prepared.galleryItems || []);
	const primaryProductImagePayload = buildProductImagePayload(
		prepared.primaryProducts || [],
	);
	const relatedProductImagePayload = buildProductImagePayload(
		prepared.relatedProducts || [],
	);

	return {
		name: link.name,
		slug: link.slug,
		status: link.status || "draft",
		target_type: link.target_type,
		target_payload: link.target_payload || {},
		public_title: nullIfEmpty(link.public_title),
		public_subtitle: nullIfEmpty(link.public_subtitle),
		public_description: nullIfEmpty(link.public_description),
		eyebrow: nullIfEmpty(link.eyebrow),
		primary_cta_label: nullIfEmpty(link.primary_cta_label),
		secondary_cta_label: nullIfEmpty(link.secondary_cta_label),
		show_prices: link.show_prices ?? true,
		show_brand_logo: link.show_brand_logo ?? true,
		show_campaign_dates: link.show_campaign_dates ?? false,
		landing_layout: link.landing_layout || "default",
		landing_template: link.landing_template || "conversion",
		editorial_eyebrow: nullIfEmpty(link.editorial_eyebrow),
		editorial_title: nullIfEmpty(link.editorial_title),
		editorial_body: nullIfEmpty(link.editorial_body),
		editorial_items: link.editorial_items || [],
		hero_image_source: link.hero_image_source || "none",
		hero_image_url:
			link.hero_image_source === "external"
				? nullIfEmpty(link.hero_image_url)
				: null,
		hero_image_alt: nullIfEmpty(link.hero_image_alt),
		hero_image:
			link.hero_image_source === "upload" ? link.hero_image || null : null,
		utm_source: nullIfEmpty(link.utm_source),
		utm_medium: nullIfEmpty(link.utm_medium),
		utm_campaign: nullIfEmpty(link.utm_campaign),
		utm_term: nullIfEmpty(link.utm_term),
		utm_content: nullIfEmpty(link.utm_content),
		starts_at: fromDatetimeLocalValue(link.starts_at),
		ends_at: fromDatetimeLocalValue(link.ends_at),
		source_link_id: link.source_link_id || null,
		reuse_source_media: Boolean(link.reuse_source_media),
		primary_laboratory_test_ids: (prepared.primaryProducts || []).map(
			(item) => item.id,
		),
		related_laboratory_test_ids: (prepared.relatedProducts || []).map(
			(item) => item.id,
		),
		primary_product_images: JSON.stringify(primaryProductImagePayload.product_images),
		primary_product_image_uploads: primaryProductImagePayload.product_image_uploads,
		related_product_images: JSON.stringify(relatedProductImagePayload.product_images),
		related_product_image_uploads: relatedProductImagePayload.product_image_uploads,
		related_category_ids: (prepared.relatedCategories || []).map(
			(item) => item.id,
		),
		gallery_items: JSON.stringify(galleryPayload.gallery_items),
		gallery_uploads: galleryPayload.gallery_uploads,
	};
}

export function buildSetupPayload(state, context, activate = false) {
	const prepared = applySmartDefaults(state, context);
	const linkPayload = buildLinkPayload(prepared, context);
	const collection =
		prepared.promotion === "new_collection"
			? {
					name: prepared.newCollection.name,
					public_title: nullIfEmpty(prepared.newCollection.public_title),
					public_description: nullIfEmpty(
						prepared.newCollection.public_description,
					),
					laboratory_brand: prepared.newCollection.laboratory_brand,
					is_active: true,
					laboratory_test_ids: (
						prepared.newCollection.laboratory_test_ids?.length
							? prepared.newCollection.laboratory_test_ids
							: prepared.primaryProducts || []
					).map((item) =>
						typeof item === "object" ? Number(item.id) : Number(item),
					),
				}
			: null;

	return {
		activate,
		campaign: {
			name: prepared.campaign.name,
			description: nullIfEmpty(prepared.campaign.description),
			status: activate ? "active" : prepared.campaign.status || "draft",
			starts_at: fromDatetimeLocalValue(prepared.campaign.starts_at),
			ends_at: fromDatetimeLocalValue(prepared.campaign.ends_at),
		},
		collection,
		link: {
			...linkPayload,
			status: activate ? "active" : linkPayload.status,
		},
	};
}
