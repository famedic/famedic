import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";

function brandLabel(collection) {
	if (collection.laboratory_brand_label) {
		return collection.laboratory_brand_label;
	}
	const brand = collection.laboratory_brand;
	if (brand == null) return "—";
	if (typeof brand === "object") {
		return brand.label ?? brand.name ?? brand.value ?? "—";
	}
	return String(brand);
}

export default function MarketingCampaignCollectionsTable({
	campaignId,
	collections = [],
	canEdit = false,
}) {
	if (!collections.length) {
		return (
			<EmptyListCard
				heading="Sin colecciones"
				message="Aún no hay colecciones en esta campaña."
			/>
		);
	}

	return (
		<Table dense className="[--gutter:theme(spacing.6)]">
			<TableHead>
				<TableRow>
					<TableHeader>Nombre</TableHeader>
					<TableHeader>Título público</TableHeader>
					<TableHeader>Marca</TableHeader>
					<TableHeader>Activa</TableHeader>
					<TableHeader>Estudios</TableHeader>
					{canEdit && (
						<TableHeader className="text-right">
							Acciones
						</TableHeader>
					)}
				</TableRow>
			</TableHead>
			<TableBody>
				{collections.map((collection) => (
					<TableRow key={collection.id}>
						<TableCell className="font-medium">
							{collection.name}
						</TableCell>
						<TableCell>{collection.public_title || "—"}</TableCell>
						<TableCell>{brandLabel(collection)}</TableCell>
						<TableCell>
							{collection.is_active ? (
								<Badge color="green">Activa</Badge>
							) : (
								<Badge color="zinc">Inactiva</Badge>
							)}
						</TableCell>
						<TableCell>
							{collection.items_count ??
								collection.items?.length ??
								0}
						</TableCell>
						{canEdit && (
							<TableCell className="text-right">
								<Button
									href={route(
										"admin.marketing-campaigns.collections.edit",
										{
											marketing_campaign: campaignId,
											marketing_campaign_collection:
												collection.id,
										},
									)}
									outline
								>
									Editar
								</Button>
							</TableCell>
						)}
					</TableRow>
				))}
			</TableBody>
		</Table>
	);
}
