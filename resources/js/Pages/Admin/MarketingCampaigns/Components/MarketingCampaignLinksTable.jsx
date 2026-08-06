import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import MarketingCampaignStatusBadge from "./MarketingCampaignStatusBadge";

const TARGET_TYPE_LABELS = {
	brand: "Marca",
	category: "Categoría",
	product: "Producto",
	collection: "Colección",
};

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

function normalizeValue(value) {
	if (value == null) return "";
	if (typeof value === "object") {
		return String(value.value ?? value.name ?? "");
	}
	return String(value);
}

export default function MarketingCampaignLinksTable({
	campaignId,
	links = [],
	canEdit = false,
}) {
	if (!links.length) {
		return (
			<EmptyListCard
				heading="Sin enlaces"
				message="Aún no hay enlaces en esta campaña."
			/>
		);
	}

	return (
		<Table dense className="[--gutter:theme(spacing.6)]">
			<TableHead>
				<TableRow>
					<TableHeader>Nombre</TableHeader>
					<TableHeader>Slug</TableHeader>
					<TableHeader>Estado</TableHeader>
					<TableHeader>Destino</TableHeader>
					<TableHeader>Vigencia</TableHeader>
					{canEdit && (
						<TableHeader className="text-right">
							Acciones
						</TableHeader>
					)}
				</TableRow>
			</TableHead>
			<TableBody>
				{links.map((link) => {
					const targetType = normalizeValue(link.target_type);
					return (
						<TableRow key={link.id}>
							<TableCell className="font-medium">
								{link.name}
							</TableCell>
							<TableCell>
								<Text className="font-mono text-sm">
									{link.slug}
								</Text>
							</TableCell>
							<TableCell>
								<MarketingCampaignStatusBadge
									status={link.status}
									label={link.status_label}
									kind="link"
								/>
							</TableCell>
							<TableCell>
								{link.target_type_label ||
									TARGET_TYPE_LABELS[targetType] ||
									targetType ||
									"—"}
							</TableCell>
							<TableCell className="text-sm">
								<div>{formatDateTime(link.starts_at)}</div>
								<div className="text-zinc-500">
									{formatDateTime(link.ends_at)}
								</div>
							</TableCell>
							{canEdit && (
								<TableCell className="text-right">
									<Button
										href={route(
											"admin.marketing-campaigns.links.edit",
											{
												marketing_campaign: campaignId,
												marketing_campaign_link:
													link.id,
											},
										)}
										outline
									>
										Editar
									</Button>
								</TableCell>
							)}
						</TableRow>
					);
				})}
			</TableBody>
		</Table>
	);
}
