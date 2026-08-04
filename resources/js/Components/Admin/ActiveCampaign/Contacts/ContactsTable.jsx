import { Badge } from "@/Components/Catalyst/badge";
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
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import { EyeIcon } from "@heroicons/react/16/solid";

function Dash({ value }) {
	if (value === null || value === undefined || value === "" || value === "—") {
		return <span className="text-zinc-400">—</span>;
	}
	return value;
}

export default function ContactsTable({
	contacts,
	filterBadges = [],
	onOpenContact,
}) {
	if (!contacts?.data?.length) {
		return (
			<EmptyListCard
				heading="Sin contactos"
				message="No hay pacientes que coincidan con los filtros actuales."
			/>
		);
	}

	return (
		<div className="space-y-4">
			<SearchResultsWithFilters
				paginatedData={contacts}
				filterBadges={filterBadges}
			/>

			<PaginatedTable paginatedData={contacts}>
				<Table
					bleed
					className="[--gutter:theme(spacing.6)]"
					dense
				>
					<TableHead>
						<TableRow>
							<TableHeader>Paciente</TableHeader>
							<TableHeader>Correo</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Teléfono
							</TableHeader>
							<TableHeader className="hidden xl:table-cell">
								Ciudad
							</TableHeader>
							<TableHeader className="hidden xl:table-cell">
								Última actividad
							</TableHeader>
							<TableHeader className="hidden 2xl:table-cell">
								Última compra
							</TableHeader>
							<TableHeader className="hidden 2xl:table-cell">
								Laboratorio
							</TableHeader>
							<TableHeader>Membresía</TableHeader>
							<TableHeader className="hidden md:table-cell">
								Estado
							</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Tags
							</TableHeader>
							<TableHeader>Journey</TableHeader>
							<TableHeader className="text-right">
								Acciones
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{contacts.data.map((row) => (
							<TableRow
								key={row.id}
								className="cursor-pointer focus-visible:outline focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-famedic-dark dark:focus-visible:outline-famedic-light"
								tabIndex={0}
								role="button"
								aria-label={`Abrir vista 360 de ${row.name}`}
								onClick={() => onOpenContact(row)}
								onKeyDown={(e) => {
									if (e.key === "Enter" || e.key === " ") {
										e.preventDefault();
										onOpenContact(row);
									}
								}}
							>
								<TableCell>
									<div className="min-w-0">
										<p className="font-medium text-zinc-950 dark:text-white">
											{row.name}
										</p>
										<p className="text-[11px] text-zinc-400">
											#{row.id}
											{row.created_at ? ` · ${row.created_at}` : ""}
										</p>
									</div>
								</TableCell>
								<TableCell className="max-w-[12rem] truncate">
									<Dash value={row.email} />
								</TableCell>
								<TableCell className="hidden lg:table-cell">
									<Dash value={row.phone} />
								</TableCell>
								<TableCell className="hidden xl:table-cell">
									<Dash value={row.city} />
								</TableCell>
								<TableCell className="hidden xl:table-cell">
									<Dash value={row.last_activity} />
								</TableCell>
								<TableCell className="hidden 2xl:table-cell">
									<Dash value={row.last_purchase} />
								</TableCell>
								<TableCell className="hidden 2xl:table-cell">
									<Dash value={row.laboratory} />
								</TableCell>
								<TableCell>
									{row.membership === "—" ? (
										<Dash value="—" />
									) : (
										<Badge
											color={
												row.membership_active ? "emerald" : "zinc"
											}
										>
											{row.membership}
										</Badge>
									)}
								</TableCell>
								<TableCell className="hidden md:table-cell">
									<Dash value={row.status} />
								</TableCell>
								<TableCell className="hidden lg:table-cell">
									{row.tags?.length ? (
										<div className="flex flex-wrap gap-1">
											{row.tags.map((tag) => (
												<Badge key={tag} color="zinc">
													{tag}
												</Badge>
											))}
										</div>
									) : (
										<Dash value="—" />
									)}
								</TableCell>
								<TableCell>
									<Text className="text-xs font-medium text-famedic-light">
										Abrir 360
									</Text>
								</TableCell>
								<TableCell className="text-right">
									<Button
										plain
										aria-label={`Abrir vista 360 de ${row.name}`}
										onClick={(e) => {
											e.stopPropagation();
											onOpenContact(row);
										}}
									>
										<EyeIcon className="size-4" />
									</Button>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</div>
	);
}
