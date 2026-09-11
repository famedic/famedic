import { useMemo } from "react";
import { Button } from "@/Components/Catalyst/button";

export default function CampaignCategoryFilters({ products = [], selectedCategory, onSelectCategory }) {
	const categories = useMemo(() => {
		const values = new Set();
		products.forEach((product) => {
			if (product.category) values.add(product.category);
		});
		return [...values].sort((a, b) => a.localeCompare(b, "es"));
	}, [products]);

	if (categories.length < 2) return null;

	return (
		<nav aria-label="Filtrar estudios por categoría" className="flex gap-2 overflow-x-auto pb-1">
			<Button
				type="button"
				outline={selectedCategory !== ""}
				onClick={() => onSelectCategory("")}
			>
				Todos
			</Button>
			{categories.map((category) => (
				<Button
					key={category}
					type="button"
					outline={selectedCategory !== category}
					onClick={() => onSelectCategory(category)}
				>
					{category}
				</Button>
			))}
		</nav>
	);
}
