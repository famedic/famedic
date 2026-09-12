import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";

function brandLabel(collection, brands = {}) {
	const value =
		collection.laboratory_brand ||
		collection.laboratory_brand_label ||
		"";
	if (collection.laboratory_brand_label) {
		return collection.laboratory_brand_label;
	}
	return brands[value]?.label || brands[value]?.name || value || "—";
}

export default function MarketingCampaignCollectionPreview({
	collection,
	brands = {},
	previewItems = [],
	campaignId = null,
	onEdit,
}) {
	if (!collection?.id) {
		return (
			<Text className="text-sm text-zinc-500">
				Selecciona una colección de estudios para ver su resumen.
			</Text>
		);
	}

	const itemsCount =
		collection.items_count ?? previewItems.length ?? 0;
	const isEmpty = itemsCount === 0;
	const isInactive = collection.is_active === false;

	return (
		<Card className="space-y-4 p-4">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div>
					<Text className="font-semibold">
						{collection.public_title || collection.name}
					</Text>
					<Text className="mt-1 text-sm text-zinc-500">
						{brandLabel(collection, brands)} · {itemsCount} estudios
					</Text>
				</div>
				<div className="flex flex-wrap gap-2">
					{collection.is_active ? (
						<Badge color="green">Activa</Badge>
					) : (
						<Badge color="zinc">Inactiva</Badge>
					)}
					{isEmpty && <Badge color="amber">Vacía</Badge>}
				</div>
			</div>

			{isInactive && (
				<Text className="text-sm text-amber-700 dark:text-amber-400">
					Esta colección está inactiva. Los enlaces pueden seguir
					apuntando a ella, pero conviene revisarla.
				</Text>
			)}

			{isEmpty && (
				<Text className="text-sm text-zinc-500">
					La colección no tiene estudios todavía. Puedes usarla en un
					enlace, pero la landing dependerá del fallback del destino.
				</Text>
			)}

			{previewItems.length > 0 && (
				<ul className="space-y-2">
					{previewItems.slice(0, 5).map((item) => (
						<li
							key={item.id}
							className="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700"
						>
							<Text className="font-medium">{item.name}</Text>
							{item.category_name && (
								<Text className="text-zinc-500">
									{item.category_name}
								</Text>
							)}
						</li>
					))}
				</ul>
			)}

			{campaignId && onEdit && (
				<Button type="button" outline onClick={onEdit}>
					Editar colección
				</Button>
			)}
		</Card>
	);
}
