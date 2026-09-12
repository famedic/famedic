import { useMemo } from "react";

export function useCampaignCategories(products = []) {
	return useMemo(() => {
		const values = new Set();
		products.forEach((product) => {
			if (product.category) values.add(product.category);
		});
		return [...values].sort((a, b) => a.localeCompare(b, "es"));
	}, [products]);
}

export default function CampaignCategoryFilters({ categories = [], selectedCategory, onSelectCategory }) {
	if (categories.length < 2) return null;

	const options = ["", ...categories];

	return (
		<nav aria-label="Filtrar estudios por categoria" className="flex gap-2 overflow-x-auto pb-1">
			{options.map((category) => {
				const active = selectedCategory === category;
				return (
					<button
						key={category || "all"}
						type="button"
						className={`shrink-0 rounded-full px-4 py-2 text-sm font-semibold transition focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime ${
							active
								? "bg-famedic-dark text-white"
								: "bg-white text-famedic-darker ring-1 ring-zinc-200 hover:ring-famedic-lime"
						}`}
						onClick={() => onSelectCategory(category)}
					>
						{category || "Todos"}
					</button>
				);
			})}
		</nav>
	);
}
