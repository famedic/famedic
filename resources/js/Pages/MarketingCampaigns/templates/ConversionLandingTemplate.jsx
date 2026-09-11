import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignTrustStrip from "../components/CampaignTrustStrip";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignSteps from "../components/CampaignSteps";
import CampaignFinalCta from "../components/CampaignFinalCta";
import CampaignBrandLogo from "../components/CampaignBrandLogo";

export default function ConversionLandingTemplate(props) {
	const {
		content,
		brand,
		category,
		starts,
		ends,
		catalogUrl,
		brandStoresUrl,
		primaryAction,
		secondaryAction,
		products,
		relatedProducts,
		relatedCategories,
		emptyMessage,
		productCardProps,
		actionButtonProps,
	} = props;

	return (
		<>
			<CampaignHero
				content={content}
				brand={brand}
				category={category}
				starts={starts}
				ends={ends}
				primaryAction={primaryAction}
				secondaryAction={secondaryAction}
				actionButtonProps={actionButtonProps}
			/>
			{brand && (
				<section className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
					<div className="flex flex-wrap items-center gap-4">
						<CampaignBrandLogo brand={brand} showLogo={content?.show_brand_logo} />
						<div>
							<Text className="font-semibold">{brand.label}</Text>
							{Array.isArray(brand.states) && brand.states.length > 0 && (
								<Text className="mt-1 text-sm text-zinc-500">
									Disponible en: {brand.states.join(", ")}
								</Text>
							)}
						</div>
					</div>
					<div className="mt-4 flex flex-wrap gap-3">
						{catalogUrl && (
							<Button {...actionButtonProps(catalogUrl, { color: "lime" })}>Ver estudios de la marca</Button>
						)}
						{brandStoresUrl && (
							<Button {...actionButtonProps(brandStoresUrl, { outline: true })}>Consultar sucursales</Button>
						)}
					</div>
				</section>
			)}
			<CampaignTrustStrip brand={brand} storesUrl={brandStoresUrl} actionButtonProps={actionButtonProps} />
			<CampaignProductGrid
				title="Estudios destacados"
				products={products}
				limit={4}
				emptyMessage={emptyMessage}
				productCardProps={productCardProps}
				actionButtonProps={actionButtonProps}
			/>
			<CampaignSteps />
			{relatedProducts.length > 0 && (
				<CampaignProductGrid
					title="También te puede interesar"
					products={relatedProducts}
					productCardProps={productCardProps}
					actionButtonProps={actionButtonProps}
				/>
			)}
			{relatedCategories.length > 0 && (
				<section className="space-y-4">
					<Text className="font-semibold">Categorías relacionadas</Text>
					<div className="flex flex-wrap gap-3">
						{relatedCategories.map((item) => (
							<Button key={item.url} {...actionButtonProps(item.url, { outline: true })}>{item.name}</Button>
						))}
					</div>
				</section>
			)}
			<CampaignFinalCta
				catalogUrl={catalogUrl}
				brandStoresUrl={brandStoresUrl}
				primaryAction={primaryAction}
				actionButtonProps={actionButtonProps}
			/>
		</>
	);
}
