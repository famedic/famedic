import { Subheading } from "@/Components/Catalyst/heading";
import EmptyListCard from "@/Components/EmptyListCard";
import CampaignProductCard from "./CampaignProductCard";

export default function CampaignProductGrid({
	title,
	products = [],
	emptyMessage,
	productCardProps,
	compact = false,
	limit,
	actionButtonProps,
}) {
	const visibleProducts = Number.isInteger(limit) ? products.slice(0, limit) : products;
	const columns = compact
		? "grid gap-4 sm:grid-cols-2 xl:grid-cols-4"
		: "grid gap-6 md:grid-cols-2 lg:gap-8 xl:grid-cols-3";

	return (
		<section className="space-y-4">
			<Subheading>{title}</Subheading>
			{visibleProducts.length === 0 ? (
				<EmptyListCard
					heading="Sin estudios"
					message={emptyMessage || "No hay estudios disponibles en esta campaña por el momento."}
				/>
			) : (
				<div className={columns}>
					{visibleProducts.map((product) => (
						<CampaignProductCard
							key={product.id}
							product={product}
							compact={compact}
							actionButtonProps={actionButtonProps}
							{...productCardProps(product)}
						/>
					))}
				</div>
			)}
		</section>
	);
}
