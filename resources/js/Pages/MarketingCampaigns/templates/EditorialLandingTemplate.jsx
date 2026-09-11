import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignEditorialSection from "../components/CampaignEditorialSection";
import CampaignTrustStrip from "../components/CampaignTrustStrip";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignFinalCta from "../components/CampaignFinalCta";

export default function EditorialLandingTemplate(props) {
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
				variant="wide"
			/>
			<CampaignEditorialSection editorial={content?.editorial} />
			<CampaignTrustStrip brand={brand} storesUrl={brandStoresUrl} actionButtonProps={actionButtonProps} />
			<CampaignProductGrid
				title="Estudios recomendados"
				products={products}
				limit={4}
				emptyMessage={emptyMessage}
				productCardProps={productCardProps}
				actionButtonProps={actionButtonProps}
			/>
			{relatedCategories.length > 0 && (
				<section className="space-y-4">
					<Text className="font-semibold">Explora más temas</Text>
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
