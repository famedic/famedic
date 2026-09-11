import { useState } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import MarketingCampaignLinkForm from "../Components/MarketingCampaignLinkForm";
import MarketingCampaignLandingPreview from "../Components/MarketingCampaignLandingPreview";
import {
	toDatetimeLocalValue,
	fromDatetimeLocalValue,
} from "../Components/MarketingCampaignDateRangeFields";
import { buildGalleryPayload } from "../Components/marketingCampaignLinkBrand";

function normalizeEnum(value, fallback = "") {
	if (value == null) return fallback;
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? fallback);
	}
	return String(value);
}

function normalizeLandingForm(form, primaryProducts, relatedProducts, relatedCategoryItems, galleryItems) {
	const galleryPayload = buildGalleryPayload(galleryItems);

	return {
		...form,
		starts_at: fromDatetimeLocalValue(form.starts_at),
		ends_at: fromDatetimeLocalValue(form.ends_at),
		public_title: form.public_title || null,
		public_subtitle: form.public_subtitle || null,
		public_description: form.public_description || null,
		eyebrow: form.eyebrow || null,
		primary_cta_label: form.primary_cta_label || null,
		secondary_cta_label: form.secondary_cta_label || null,
		landing_layout: form.landing_layout || "default",
		landing_template: form.landing_template || "conversion",
		editorial_eyebrow: form.editorial_eyebrow || null,
		editorial_title: form.editorial_title || null,
		editorial_body: form.editorial_body || null,
		editorial_items: form.editorial_items || [],
		utm_source: form.utm_source || null,
		utm_medium: form.utm_medium || null,
		utm_campaign: form.utm_campaign || null,
		utm_term: form.utm_term || null,
		utm_content: form.utm_content || null,
		hero_image_source: form.hero_image_source || "none",
		hero_image_url:
			form.hero_image_source === "external"
				? form.hero_image_url || null
				: null,
		hero_image_alt: form.hero_image_alt || null,
		hero_image:
			form.hero_image_source === "upload" ? form.hero_image || null : null,
		primary_laboratory_test_ids: primaryProducts.map((item) => item.id),
		related_laboratory_test_ids: relatedProducts.map((item) => item.id),
		related_category_ids: relatedCategoryItems.map((item) => item.id),
		gallery_items: JSON.stringify(galleryPayload.gallery_items),
		gallery_uploads: galleryPayload.gallery_uploads,
	};
}

function initialGalleryItems(images = []) {
	return images.map((image) => ({
		kind: "existing",
		key: `existing-${image.id}`,
		id: image.id,
		url: image.url,
		alt: image.alt || "",
	}));
}

function formatPrice(product) {
	if (!product?.famedic_price_cents && product?.famedic_price_cents !== 0) {
		return null;
	}
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(product.famedic_price_cents / 100);
}

export default function MarketingCampaignLinksEdit({
	campaign,
	link,
	statusOptions = {},
	brands = {},
	categories = [],
	collections = [],
	landingTemplateOptions = [],
	productSearchUrl,
	aliases = [],
}) {
	const [primaryProducts, setPrimaryProducts] = useState(
		link.primary_products || [],
	);
	const [relatedProducts, setRelatedProducts] = useState(
		link.related_products || [],
	);
	const [relatedCategoryItems, setRelatedCategoryItems] = useState(
		link.related_categories || [],
	);
	const [galleryItems, setGalleryItems] = useState(
		initialGalleryItems(link.gallery_images || []),
	);

	const { data, setData, put, processing, errors, transform } = useForm({
		name: link.name || "",
		slug: link.slug || "",
		status: normalizeEnum(link.status, "draft"),
		target_type: normalizeEnum(link.target_type, "brand"),
		target_payload: link.target_payload || {},
		public_title: link.public_title || "",
		public_subtitle: link.public_subtitle || "",
		public_description: link.public_description || "",
		eyebrow: link.eyebrow || "",
		hero_image_source: link.hero_image_source || "none",
		hero_image_url: link.hero_image_url || "",
		hero_image_alt: link.hero_image_alt || "",
		hero_image: null,
		primary_cta_label: link.primary_cta_label || "",
		secondary_cta_label: link.secondary_cta_label || "",
		show_prices: link.show_prices ?? true,
		show_brand_logo: link.show_brand_logo ?? true,
		show_campaign_dates: link.show_campaign_dates ?? false,
		landing_layout: link.landing_layout || "default",
		landing_template: link.landing_template || "conversion",
		editorial_eyebrow: link.editorial_eyebrow || "",
		editorial_title: link.editorial_title || "",
		editorial_body: link.editorial_body || "",
		editorial_items: link.editorial_items || [],
		utm_source: link.utm_source || "",
		utm_medium: link.utm_medium || "",
		utm_campaign: link.utm_campaign || "",
		utm_term: link.utm_term || "",
		utm_content: link.utm_content || "",
		starts_at: toDatetimeLocalValue(link.starts_at),
		ends_at: toDatetimeLocalValue(link.ends_at),
	});

	transform((form) =>
		normalizeLandingForm(
			form,
			primaryProducts,
			relatedProducts,
			relatedCategoryItems,
			galleryItems,
		),
	);

	const submit = (e) => {
		e.preventDefault();
		if (!processing) {
			put(
				route("admin.marketing-campaigns.links.update", {
					marketing_campaign: campaign.id,
					marketing_campaign_link: link.id,
				}),
				{ forceFormData: true },
			);
		}
	};

	const aliasList = aliases.length ? aliases : link.aliases || [];
	const previewBrandValue = data.target_payload?.brand || primaryProducts[0]?.brand;
	const previewBrand = previewBrandValue ? brands[previewBrandValue] : null;
	const liveHeroPreviewUrl =
		data.hero_image_source === "external"
			? data.hero_image_url
			: data.hero_image instanceof File
				? URL.createObjectURL(data.hero_image)
				: link.hero_image_preview_url || null;
	const previewGallery = galleryItems
		.map((item) => ({
			key: item.key || item.id,
			url:
				item.url ||
				(item.file instanceof File ? URL.createObjectURL(item.file) : null),
			alt: item.alt,
		}))
		.filter((item) => item.url);
	const previewProducts = primaryProducts.map((product) => ({
		...product,
		price_label: formatPrice(product),
	}));

	return (
		<AdminLayout title={`Editar enlace · ${link.name}`}>
			<div className="mx-auto max-w-7xl space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-4">
					<div>
						<Heading>Editar enlace</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Campaña: {campaign.name}
						</Text>
					</div>
					<Button
						href={route(
							"admin.marketing-campaigns.show",
							campaign.id,
						)}
						outline
					>
						Volver a la campaña
					</Button>
					{link.preview_url && (
						<Button href={link.preview_url} target="_blank" outline>
							Vista previa real
						</Button>
					)}
				</div>

				<div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_26rem]">
					<MarketingCampaignLinkForm
						data={data}
						setData={setData}
						errors={errors}
						statusOptions={statusOptions}
						brands={brands}
						categories={categories}
						collections={collections}
						landingTemplateOptions={landingTemplateOptions}
						productSearchUrl={productSearchUrl}
						aliases={aliasList}
						isEdit
						processing={processing}
						onSubmit={submit}
						submitLabel="Guardar enlace"
						primaryProducts={primaryProducts}
						onPrimaryProductsChange={setPrimaryProducts}
						relatedProducts={relatedProducts}
						onRelatedProductsChange={setRelatedProducts}
						relatedCategoryItems={relatedCategoryItems}
						onRelatedCategoryItemsChange={setRelatedCategoryItems}
						galleryItems={galleryItems}
						onGalleryItemsChange={setGalleryItems}
						heroPreviewUrl={link.hero_image_preview_url || null}
					/>
					<aside className="sticky top-6 self-start">
						<MarketingCampaignLandingPreview
							content={{
								eyebrow: data.eyebrow,
								public_title: data.public_title,
								public_subtitle: data.public_subtitle,
								public_description: data.public_description,
								hero_url: liveHeroPreviewUrl,
								hero_alt: data.hero_image_alt,
								landing_template: data.landing_template || "conversion",
								editorial: {
									eyebrow: data.editorial_eyebrow,
									title: data.editorial_title,
									body: data.editorial_body,
									items: data.editorial_items || [],
								},
							}}
							brand={
								previewBrand
									? {
											label: previewBrand.label || previewBrand.name,
											logo_url: previewBrand.logo_url,
										}
									: null
							}
							products={previewProducts}
							relatedProducts={relatedProducts}
							gallery={previewGallery}
							showPrices={data.show_prices}
							showLogo={data.show_brand_logo}
							landingTemplate={data.landing_template || "conversion"}
							primaryAction={{ label: data.primary_cta_label }}
							secondaryAction={{ label: data.secondary_cta_label }}
						/>
					</aside>
				</div>
			</div>
		</AdminLayout>
	);
}
