import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
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

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

export default function MarketingCampaignCollectionsTable({
	campaignId,
	collections = [],
	canEdit = false,
	createHref = null,
}) {
	if (!collections.length) {
		return (
			<div className="space-y-4">
				<EmptyListCard
					heading="Sin colecciones"
					message="Solo necesitas una colección cuando reutilizarás un grupo de estudios."
				/>
				{createHref && (
					<div className="flex justify-center">
						<Button href={createHref} color="lime">
							Crear colección
						</Button>
					</div>
				)}
			</div>
		);
	}

	const renderActions = (collection) => (
		<div className="flex flex-wrap justify-end gap-2">
			{canEdit && (
				<Button href={collection.edit_url} outline>
					Editar
				</Button>
			)}
			{canEdit && collection.create_link_url && (
				<Button href={collection.create_link_url} outline>
					Usar en nuevo enlace
				</Button>
			)}
			{collection.primary_link?.public_url && (
				<Button
					type="button"
					outline
					onClick={() =>
						window.open(
							collection.primary_link.public_url,
							"_blank",
							"noopener,noreferrer",
						)
					}
				>
					Abrir enlace
				</Button>
			)}
		</div>
	);

	return (
		<>
			<div className="hidden md:block">
				<Table dense className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Colección</TableHeader>
							<TableHeader>Marca</TableHeader>
							<TableHeader>Estudios</TableHeader>
							<TableHeader>Enlaces</TableHeader>
							<TableHeader>Estado</TableHeader>
							<TableHeader>Actualizada</TableHeader>
							<TableHeader className="text-right">
								Acciones
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{collections.map((collection) => (
							<TableRow key={collection.id}>
								<TableCell className="font-medium">
									{collection.name}
								</TableCell>
								<TableCell>{brandLabel(collection)}</TableCell>
								<TableCell>
									{collection.items_count ??
										collection.items?.length ??
										0}
								</TableCell>
								<TableCell>
									{collection.links_count ?? 0}
								</TableCell>
								<TableCell>
									{collection.is_active ? (
										<Badge color="green">Activa</Badge>
									) : (
										<Badge color="zinc">Inactiva</Badge>
									)}
								</TableCell>
								<TableCell className="text-sm">
									{formatDateTime(collection.updated_at)}
								</TableCell>
								<TableCell className="text-right">
									{renderActions(collection)}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>

			<div className="space-y-3 md:hidden">
				{collections.map((collection) => (
					<div
						key={collection.id}
						className="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700"
					>
						<div className="flex items-start justify-between gap-3">
							<div>
								<Text className="font-semibold">
									{collection.name}
								</Text>
								<Text className="mt-1 text-sm text-zinc-500">
									{brandLabel(collection)} ·{" "}
									{collection.items_count ?? 0} estudios ·{" "}
									{collection.links_count ?? 0} enlaces
								</Text>
								<Text className="mt-1 text-xs text-zinc-400">
									Actualizada{" "}
									{formatDateTime(collection.updated_at)}
								</Text>
							</div>
							{collection.is_active ? (
								<Badge color="green">Activa</Badge>
							) : (
								<Badge color="zinc">Inactiva</Badge>
							)}
						</div>
						<div className="mt-4">{renderActions(collection)}</div>
					</div>
				))}
			</div>
		</>
	);
}
