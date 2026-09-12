import { Subheading } from "@/Components/Catalyst/heading";
import EmptyListCard from "@/Components/EmptyListCard";
import CampaignProductCard from "./CampaignProductCard";

const GRID_COLUMNS = {
	editorial: "grid gap-5 md:grid-cols-2 lg:grid-cols-3",
	conversion: "grid gap-5 md:grid-cols-2 xl:grid-cols-3",
	catalog: "grid gap-5 md:grid-cols-2 xl:grid-cols-3",
};

export default function CampaignProductGrid({
	title,
	subtitle,
	products = [],
	emptyMessage,
	productCardProps,
	compact = false,
	limit,
	actionButtonProps,
	variant = "conversion",
}) {
	const visibleProducts = Number.isInteger(limit) ? products.slice(0, limit) : products;
	const columns = GRID_COLUMNS[variant] || GRID_COLUMNS.conversion;

	return (
		<section className="space-y-5">
			{(title || subtitle) && (
				<div className="space-y-1">
					{title && (
						<Subheading className="font-poppins text-2xl font-semibold text-famedic-darker">
							{title}
						</Subheading>
					)}
					{subtitle && <p className="text-sm leading-6 text-slate-600">{subtitle}</p>}
				</div>
			)}
			{visibleProducts.length === 0 ? (
				<EmptyListCard
					heading="Sin estudios"
					message={emptyMessage || "No hay estudios disponibles en esta campaña por el momento."}
				/>
			) : (
				<div className={columns}>
					{visibleProducts.map((product, index) => (
						<CampaignProductCard
							key={product.id}
							product={product}
							position={index}
							compact={compact}
							actionButtonProps={actionButtonProps}
							variant={variant}
							{...productCardProps(product)}
						/>
					))}
				</div>
			)}
		</section>
	);
}
