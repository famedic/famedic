import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignEditorialSection from "../components/CampaignEditorialSection";
import CampaignTrustStrip from "../components/CampaignTrustStrip";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignFinalCta from "../components/CampaignFinalCta";

function EditorialGallery({ images = [] }) {
	if (!images.length) return null;

	return (
		<section className="space-y-5">
			<Text className="font-poppins text-2xl font-semibold text-famedic-darker">Galeria</Text>
			<div className="grid gap-4 sm:grid-cols-2">
				{images.map((image, index) => (
					<div key={`${image.url}-${index}`} className="overflow-hidden rounded-2xl bg-zinc-100 ring-1 ring-zinc-200">
						<img
							src={image.url}
							alt={image.alt || ""}
							className="aspect-[4/3] w-full object-cover"
						/>
					</div>
				))}
			</div>
		</section>
	);
}

export default function EditorialLandingTemplate(props) {
	const {
		content,
		brand,
		category,
		starts,
		ends,
		startsAt,
		endsAt,
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
	const gallery = Array.isArray(content?.gallery) ? content.gallery : [];
	const secondaryImage = content?.image_roles?.editorial_secondary || gallery[0] || null;
	const galleryImages = Array.isArray(content?.image_roles?.gallery)
		? content.image_roles.gallery
		: gallery.slice(1);

	return (
		<>
			<CampaignHero
				content={content}
				brand={brand}
				category={category}
				starts={starts}
				ends={ends}
				startsAt={startsAt}
				endsAt={endsAt}
				primaryAction={primaryAction}
				secondaryAction={secondaryAction}
				actionButtonProps={actionButtonProps}
				variant="editorial"
			/>
			<CampaignEditorialSection editorial={content?.editorial} image={secondaryImage} />
			<CampaignProductGrid
				title="Estudios recomendados"
				products={products}
				limit={4}
				emptyMessage={emptyMessage}
				productCardProps={productCardProps}
				actionButtonProps={actionButtonProps}
				variant="editorial"
			/>
			<CampaignTrustStrip brand={brand} variant="editorial" />
			<EditorialGallery images={galleryImages} />
			{relatedCategories.length > 0 && (
				<section className="space-y-4">
					<Text className="font-semibold text-famedic-darker">Explora mas temas</Text>
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
			/>
		</>
	);
}
