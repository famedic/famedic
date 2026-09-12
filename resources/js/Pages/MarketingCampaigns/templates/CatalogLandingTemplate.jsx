import { useMemo, useState } from "react";
import { FunnelIcon, XMarkIcon } from "@heroicons/react/20/solid";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import CampaignHero from "../components/CampaignHero";
import CampaignCategoryFilters, { useCampaignCategories } from "../components/CampaignCategoryFilters";
import CampaignProductGrid from "../components/CampaignProductGrid";
import CampaignTrustStrip from "../components/CampaignTrustStrip";
import CampaignFinalCta from "../components/CampaignFinalCta";

function CategoryList({ categories, selectedCategory, onSelectCategory }) {
	const options = ["", ...categories];

	return (
		<div className="space-y-1.5">
			{options.map((category) => {
				const active = selectedCategory === category;
				return (
					<button
						key={category || "all"}
						type="button"
						className={`flex w-full items-center justify-between rounded-xl px-3 py-2.5 text-left text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime ${
							active
								? "bg-famedic-dark text-white"
								: "text-slate-700 hover:bg-sky-50 hover:text-famedic-darker"
						}`}
						onClick={() => onSelectCategory(category)}
					>
						<span>{category || "Todos"}</span>
					</button>
				);
			})}
		</div>
	);
}

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
		emptyMessage,
		productCardProps,
		actionButtonProps,
	} = props;
	const [selectedCategory, setSelectedCategory] = useState("");
	const [filtersOpen, setFiltersOpen] = useState(false);
	const allProducts = useMemo(() => [...products, ...relatedProducts], [products, relatedProducts]);
	const categories = useCampaignCategories(allProducts);
	const filteredProducts = useMemo(
		() => selectedCategory
			? allProducts.filter((product) => product.category === selectedCategory)
			: allProducts,
		[allProducts, selectedCategory],
	);

	const selectCategory = (value) => {
		setSelectedCategory(value);
		setFiltersOpen(false);
	};

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
				variant="catalog"
			/>
			<section className="space-y-5">
				<div className="flex flex-wrap items-center justify-between gap-3">
					<div>
						<Text className="font-poppins text-2xl font-semibold text-famedic-darker">
							Catálogo de estudios
						</Text>
						{allProducts.length > 0 && (
							<Text className="mt-1 text-sm text-zinc-500">
								{filteredProducts.length} de {allProducts.length} estudios visibles
							</Text>
						)}
					</div>
					{categories.length > 1 && (
						<Button
							type="button"
							outline
							className="lg:hidden"
							onClick={() => setFiltersOpen(true)}
						>
							<FunnelIcon />
							Filtros
						</Button>
					)}
				</div>
				<CampaignCategoryFilters
					categories={categories}
					selectedCategory={selectedCategory}
					onSelectCategory={selectCategory}
				/>
				<div className="grid gap-5 lg:grid-cols-[260px_1fr]">
					{categories.length > 1 && (
						<aside className="hidden self-start rounded-2xl bg-white p-5 shadow-sm ring-1 ring-slate-200 lg:block">
							<div className="mb-5 flex items-center gap-2 border-b border-slate-200 pb-4">
								<FunnelIcon className="size-5 text-famedic-dark" aria-hidden="true" />
								<Text className="text-sm font-semibold text-famedic-darker">
									Filtrar resultados
								</Text>
							</div>
							<Text className="mb-2 text-xs font-semibold uppercase tracking-wide text-slate-500">
								Tipo de estudio
							</Text>
							<CategoryList
								categories={categories}
								selectedCategory={selectedCategory}
								onSelectCategory={selectCategory}
							/>
						</aside>
					)}
					<CampaignProductGrid
						products={filteredProducts}
						emptyMessage={emptyMessage}
						productCardProps={productCardProps}
						compact
						actionButtonProps={actionButtonProps}
						variant="catalog"
					/>
				</div>
			</section>
			{filtersOpen && (
				<div className="fixed inset-0 z-50 bg-famedic-darker/40 p-4 lg:hidden">
					<div className="ml-auto flex h-full max-w-sm flex-col rounded-2xl bg-white p-4 shadow-xl">
						<div className="flex items-center justify-between">
							<Text className="font-semibold text-famedic-darker">Filtros</Text>
							<button
								type="button"
								className="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime"
								onClick={() => setFiltersOpen(false)}
							>
								<XMarkIcon className="size-5" />
							</button>
						</div>
						<div className="mt-5">
							<CategoryList
								categories={categories}
								selectedCategory={selectedCategory}
								onSelectCategory={selectCategory}
							/>
						</div>
					</div>
				</div>
			)}
			<CampaignTrustStrip brand={brand} variant="catalog" />
			<CampaignFinalCta
				catalogUrl={catalogUrl}
				brandStoresUrl={brandStoresUrl}
				primaryAction={primaryAction}
				actionButtonProps={actionButtonProps}
				brand={brand}
				variant="catalog"
			/>
		</>
	);
}
