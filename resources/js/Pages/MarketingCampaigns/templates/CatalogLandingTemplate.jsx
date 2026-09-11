import { useMemo, useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignCategoryFilters from "../components/CampaignCategoryFilters";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignTrustStrip from "../components/CampaignTrustStrip";
import CampaignFinalCta from "../components/CampaignFinalCta";

export default function CatalogLandingTemplate(props) {
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
	const [selectedCategory, setSelectedCategory] = useState("");
	const allProducts = [...products, ...relatedProducts];
	const filteredProducts = useMemo(
		() => selectedCategory
			? allProducts.filter((product) => product.category === selectedCategory)
			: allProducts,
		[allProducts, selectedCategory],
	);

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
				variant="compact"
			/>
			<section className="space-y-3">
				<div className="flex flex-wrap items-center justify-between gap-3">
					<Text className="font-semibold">Filtrar por categoría</Text>
					{allProducts.length > 0 && (
						<Text className="text-sm text-zinc-500">{filteredProducts.length} estudios visibles</Text>
					)}
				</div>
				<CampaignCategoryFilters
					products={allProducts}
					selectedCategory={selectedCategory}
					onSelectCategory={setSelectedCategory}
				/>
			</section>
			<CampaignProductGrid
				title={allProducts.length <= 4 ? "Estudios destacados" : "Catálogo de estudios"}
				products={filteredProducts}
				emptyMessage={emptyMessage}
				productCardProps={productCardProps}
				compact={allProducts.length > 4}
				actionButtonProps={actionButtonProps}
			/>
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
			<CampaignTrustStrip brand={brand} storesUrl={brandStoresUrl} actionButtonProps={actionButtonProps} />
			<CampaignFinalCta
				catalogUrl={catalogUrl}
				brandStoresUrl={brandStoresUrl}
				primaryAction={primaryAction}
				actionButtonProps={actionButtonProps}
			/>
		</>
	);
}
