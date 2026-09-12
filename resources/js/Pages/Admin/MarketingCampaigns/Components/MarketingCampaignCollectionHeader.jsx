import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import MarketingCampaignStatusBadge from "./MarketingCampaignStatusBadge";

function brandLabel(value, brands) {
	if (!value) return "—";
	if (brands?.[value]?.label) return brands[value].label;
	return String(value);
}

export default function MarketingCampaignCollectionHeader({
	campaign,
	collection = {},
	brands = {},
	selectedCount = 0,
}) {
	const brand = brandLabel(collection.laboratory_brand, brands);

	return (
		<div className="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-900/60">
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div className="space-y-1">
					<Text className="text-lg font-semibold">
						{collection.name || "Nueva colección de estudios"}
					</Text>
					<Text className="text-sm text-zinc-500">
						Campaña: {campaign?.name}
					</Text>
				</div>
				<div className="flex flex-wrap gap-2">
					{collection.is_active === false ? (
						<Badge color="zinc">Inactiva</Badge>
					) : (
						<Badge color="green">Activa</Badge>
					)}
				</div>
			</div>
			<div className="mt-4 grid gap-3 sm:grid-cols-3">
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Marca de laboratorio
					</Text>
					<Text className="mt-1 font-medium">{brand}</Text>
				</div>
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Estudios seleccionados
					</Text>
					<Text className="mt-1 font-medium">{selectedCount}</Text>
				</div>
				<div>
					<Text className="text-xs uppercase tracking-wide text-zinc-500">
						Estado
					</Text>
					<Text className="mt-1 font-medium">
						{collection.is_active === false ? "Inactiva" : "Activa"}
					</Text>
				</div>
			</div>
		</div>
	);
}
