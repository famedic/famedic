import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { resolveLandingTemplate } from "@/Pages/MarketingCampaigns/templates";

function formatCents(cents) {
	if (cents == null || cents === "") {
		return null;
	}

	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
	}).format(Number(cents) / 100);
}

function formatPreviewProduct(product) {
	const famedicPriceCents = product.famedic_price_cents ?? 0;
	const publicPriceCents = product.public_price_cents ?? famedicPriceCents;
	const famedicLabel =
		product.formatted_famedic_price ||
		product.price_label ||
		formatCents(famedicPriceCents) ||
		"$0.00 MXN";
	const publicLabel =
		product.formatted_public_price ||
		formatCents(publicPriceCents) ||
		famedicLabel;

	return {
		...product,
		formatted_famedic_price: famedicLabel,
		formatted_public_price: publicLabel,
		public_price_cents: publicPriceCents,
		famedic_price_cents: famedicPriceCents,
		detail_url: "#",
	};
}

export default function MarketingCampaignLandingPreview({
	content = {},
	brand = null,
	products = [],
	relatedProducts = [],
	gallery = [],
	primaryAction = {},
	secondaryAction = null,
	showPrices = true,
	showLogo = true,
	landingTemplate = "conversion",
}) {
	const [viewport, setViewport] = useState("desktop");
	const Template = resolveLandingTemplate(content.landing_template || landingTemplate);
	const normalizedContent = {
		eyebrow: content.eyebrow,
		title: content.public_title || "Título de campaña",
		subtitle: content.public_subtitle,
		description: content.public_description,
		hero_image: content.hero_url,
		hero_image_alt: content.hero_alt,
		show_prices: showPrices,
		show_brand_logo: showLogo,
		gallery,
		landing_template: content.landing_template || landingTemplate,
		editorial: content.editorial || {},
	};
	const normalizedProducts = useMemo(
		() => products.map(formatPreviewProduct),
		[products],
	);
	const normalizedRelated = useMemo(
		() => relatedProducts.map(formatPreviewProduct),
		[relatedProducts],
	);
	const frameClass =
		viewport === "mobile"
			? "mx-auto max-w-sm overflow-hidden rounded-[1.75rem] border-8 border-zinc-900 shadow-xl"
			: "overflow-hidden rounded-xl border border-zinc-200 shadow-sm dark:border-zinc-700";
	const noopActionProps = () => ({
		type: "button",
		disabled: true,
		"aria-disabled": true,
		onClick: (event) => event.preventDefault(),
	});
	const productCardProps = () => ({
		showPrices,
		cart: null,
		canAddToCart: false,
		isInCart: false,
		onAdd: () => {},
		adding: false,
		cartMessage: null,
	});

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap items-center justify-between gap-3">
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Vista previa en vivo - cambios sin guardar.
				</Text>
				<div className="flex gap-2">
					<Button
						type="button"
						outline={viewport !== "desktop"}
						onClick={() => setViewport("desktop")}
					>
						Desktop
					</Button>
					<Button
						type="button"
						outline={viewport !== "mobile"}
						onClick={() => setViewport("mobile")}
					>
						Mobile
					</Button>
				</div>
			</div>

			<div className={frameClass}>
				<div className="max-h-[42rem] space-y-8 overflow-y-auto bg-white p-5 dark:bg-zinc-950">
					<Template
						content={normalizedContent}
						brand={brand}
						category={null}
						starts={null}
						ends={null}
						catalogUrl="#"
						brandStoresUrl="#"
						primaryAction={{
							label: primaryAction?.label || "Ver estudios",
							url: "#",
						}}
						secondaryAction={
							secondaryAction?.label
								? { label: secondaryAction.label, url: "#" }
								: null
						}
						products={normalizedProducts}
						relatedProducts={normalizedRelated}
						relatedCategories={[]}
						emptyMessage="Los productos se resolverán según el destino configurado."
						productCardProps={productCardProps}
						actionButtonProps={noopActionProps}
					/>
				</div>
			</div>
		</div>
	);
}
