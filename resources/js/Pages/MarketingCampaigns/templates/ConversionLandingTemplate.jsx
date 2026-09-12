import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignSteps from "../components/CampaignSteps";
import CampaignFinalCta from "../components/CampaignFinalCta";

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
				variant="conversion"
			/>
			<CampaignProductGrid
				title="Estudios recomendados para ti"
				subtitle={`Conoce los estudios${brand?.label ? ` de ${brand.label}` : ""} disponibles para cuidar tu salud.`}
				products={products}
				limit={3}
				emptyMessage={emptyMessage}
				productCardProps={productCardProps}
				actionButtonProps={actionButtonProps}
				variant="conversion"
			/>
			<CampaignSteps />
			{relatedProducts.length > 0 && (
				<CampaignProductGrid
					title="También te puede interesar"
					products={relatedProducts}
					limit={3}
					productCardProps={productCardProps}
					actionButtonProps={actionButtonProps}
					variant="conversion"
				/>
			)}
			{relatedCategories.length > 0 && (
				<section className="space-y-4">
					<Text className="font-semibold text-famedic-darker">Categorías relacionadas</Text>
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
				brand={brand}
				variant="conversion"
			/>
		</>
	);
}
