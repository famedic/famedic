import { useMemo, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
	PlusIcon,
	MagnifyingGlassIcon,
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
} from "@heroicons/react/16/solid";
import { FunnelIcon } from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import EmptyListCard from "@/Components/EmptyListCard";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import MarketingCampaignStatusBadge from "./Components/MarketingCampaignStatusBadge";

function optionEntries(options) {
	if (!options) return [];
	if (Array.isArray(options)) {
		return options.map((option) =>
			typeof option === "string"
				? [option, option]
				: [option.value, option.label ?? option.value],
		);
	}
	return Object.entries(options);
}

function formatDateTime(value) {
	if (!value) return "—";
	try {
		return new Date(value).toLocaleString("es-MX");
	} catch {
		return String(value).slice(0, 16);
	}
}

export default function MarketingCampaignsIndex({
	campaigns,
	filters = {},
	statusOptions = {},
	capabilities = {},
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		status: filters.status || "",
		starts_at: filters.starts_at || "",
		ends_at: filters.ends_at || "",
	});

	const [showFilters, setShowFilters] = useState(false);
	const [archiveTarget, setArchiveTarget] = useState(null);
	const [archiving, setArchiving] = useState(false);

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.starts_at || "") !== (filters.starts_at || "") ||
			(data.ends_at || "") !== (filters.ends_at || ""),
		[data, filters],
	);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.marketing-campaigns.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const filterBadges = useMemo(() => {
		const badges = [];
		const statusLabels = Object.fromEntries(optionEntries(statusOptions));

		if (filters.search) {
			badges.push(
				<Badge key="search" color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.status) {
			badges.push(
				<Badge key="status" color="slate">
					{statusLabels[filters.status] || filters.status}
				</Badge>,
			);
		}

		if (filters.starts_at) {
			badges.push(
				<Badge key="starts" color="slate">
					<CalendarDateRangeIcon className="size-4" />
					desde {filters.starts_at}
				</Badge>,
			);
		}

		if (filters.ends_at) {
			badges.push(
				<Badge key="ends" color="slate">
					<CalendarDateRangeIcon className="size-4" />
					hasta {filters.ends_at}
				</Badge>,
			);
		}

		return badges;
	}, [filters, statusOptions]);

	const confirmArchive = () => {
		if (!archiveTarget || archiving) return;
		setArchiving(true);
		router.post(
			route("admin.marketing-campaigns.archive", archiveTarget.id),
			{},
			{
				preserveScroll: true,
				onFinish: () => {
					setArchiving(false);
					setArchiveTarget(null);
				},
			},
		);
	};

	return (
		<AdminLayout title="Campañas de marketing">
			<div className="space-y-8">
				<div className="flex flex-wrap items-end justify-between gap-8">
					<div>
						<Heading>Campañas de marketing</Heading>
						<Text className="mt-2 text-zinc-600 dark:text-zinc-400">
							Administra campañas, enlaces y colecciones
							promocionales.
						</Text>
					</div>
					{(capabilities.canCreate ?? capabilities.create) && (
						<Button
							href={route("admin.marketing-campaigns.create")}
							color="lime"
						>
							<PlusIcon />
							Nueva campaña
						</Button>
					)}
				</div>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<div className="flex-1 md:max-w-md">
							<InputGroup>
								<MagnifyingGlassIcon />
								<Input
									placeholder="Buscar campañas…"
									value={data.search}
									onChange={(e) =>
										setData("search", e.target.value)
									}
								/>
							</InputGroup>
						</div>
						<div className="flex items-center justify-end gap-2">
							<Button
								outline
								type="button"
								className="w-full"
								onClick={() => setShowFilters(!showFilters)}
							>
								<FunnelIcon className="size-4" />
								Filtros
								<FilterCountBadge count={filterBadges.length} />
							</Button>
						</div>
					</div>

					{showFilters && (
						<div className="grid gap-4 md:grid-cols-3">
							<ListboxFilter
								label="Estado"
								value={data.status}
								onChange={(value) => setData("status", value)}
							>
								<ListboxOption value="" className="group">
									<ArchiveBoxIcon />
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								{optionEntries(statusOptions).map(
									([value, label]) => (
										<ListboxOption
											key={value}
											value={value}
										>
											<ListboxLabel>{label}</ListboxLabel>
										</ListboxOption>
									),
								)}
							</ListboxFilter>
							<DateFilter
								label="Inicio desde"
								value={data.starts_at}
								onChange={(value) =>
									setData("starts_at", value)
								}
							/>
							<DateFilter
								label="Fin hasta"
								value={data.ends_at}
								onChange={(value) => setData("ends_at", value)}
							/>
						</div>
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton
								type="submit"
								processing={processing}
							/>
						</div>
					)}
				</form>

				{(campaigns?.data?.length ?? 0) === 0 ? (
					<EmptyListCard
						heading="Sin campañas"
						message="No hay campañas con los filtros actuales."
					/>
				) : (
					<>
						{filterBadges.length > 0 && (
							<div className="flex flex-wrap gap-2">
								{filterBadges}
							</div>
						)}
						<PaginatedTable paginatedData={campaigns}>
							<Table className="[--gutter:theme(spacing.6)]">
								<TableHead>
									<TableRow>
										<TableHeader>Nombre</TableHeader>
										<TableHeader>Estado</TableHeader>
										<TableHeader>Inicio</TableHeader>
										<TableHeader>Fin</TableHeader>
										<TableHeader>Enlaces</TableHeader>
										<TableHeader>Colecciones</TableHeader>
										<TableHeader>Creada</TableHeader>
										<TableHeader className="text-right">
											Acciones
										</TableHeader>
									</TableRow>
								</TableHead>
								<TableBody>
									{campaigns.data.map((campaign) => {
										const canEdit = Boolean(
											campaign.can_edit,
										);
										const canArchive = Boolean(
											campaign.can_archive,
										);

										return (
											<TableRow key={campaign.id}>
												<TableCell className="font-medium">
													{campaign.name}
												</TableCell>
												<TableCell>
													<MarketingCampaignStatusBadge
														status={campaign.status}
														label={
															campaign.status_label
														}
													/>
												</TableCell>
												<TableCell className="text-sm">
													{formatDateTime(
														campaign.starts_at,
													)}
												</TableCell>
												<TableCell className="text-sm">
													{formatDateTime(
														campaign.ends_at,
													)}
												</TableCell>
												<TableCell>
													{campaign.links_count ?? 0}
												</TableCell>
												<TableCell>
													{campaign.collections_count ??
														0}
												</TableCell>
												<TableCell className="text-sm">
													{formatDateTime(
														campaign.created_at,
													)}
												</TableCell>
												<TableCell className="text-right">
													<div className="flex justify-end gap-2">
														<Button
															href={route(
																"admin.marketing-campaigns.show",
																campaign.id,
															)}
															outline
														>
															Ver
														</Button>
														{canEdit && (
															<Button
																href={route(
																	"admin.marketing-campaigns.edit",
																	campaign.id,
																)}
																outline
															>
																Editar
															</Button>
														)}
														{canArchive && (
															<Button
																type="button"
																color="red"
																onClick={() =>
																	setArchiveTarget(
																		campaign,
																	)
																}
															>
																Archivar
															</Button>
														)}
													</div>
												</TableCell>
											</TableRow>
										);
									})}
								</TableBody>
							</Table>
						</PaginatedTable>
					</>
				)}
			</div>

			<DeleteConfirmationModal
				isOpen={!!archiveTarget}
				close={() => setArchiveTarget(null)}
				title="Archivar campaña"
				description={
					archiveTarget
						? `¿Archivar la campaña “${archiveTarget.name}”? Después de archivarla, la campaña quedará disponible únicamente para consulta.`
						: ""
				}
				processing={archiving}
				destroy={confirmArchive}
				confirmLabel="Archivar"
			/>
		</AdminLayout>
	);
}
