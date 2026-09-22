import { useMemo, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
	PlusIcon,
	MagnifyingGlassIcon,
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
	ArrowTopRightOnSquareIcon,
	LinkIcon,
} from "@heroicons/react/16/solid";
import { FunnelIcon } from "@heroicons/react/24/outline";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input, InputGroup } from "@/Components/Catalyst/input";
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

function formatDateRange(startsAt, endsAt) {
	if (!startsAt && !endsAt) return "Permanente";
	if (!endsAt) return `${formatDateTime(startsAt)} — Sin expiración`;
	if (!startsAt) return `Hasta ${formatDateTime(endsAt)}`;

	return `${formatDateTime(startsAt)} — ${formatDateTime(endsAt)}`;
}

function channelMeta(link) {
	const source = String(link?.utm_source || "").toLowerCase();
	const medium = String(link?.utm_medium || "").toLowerCase();

	if (source.includes("whatsapp") || medium.includes("whatsapp")) {
		return { icon: "WA", label: "WhatsApp", color: "green" };
	}
	if (source.includes("facebook") || source.includes("instagram") || medium.includes("social")) {
		return { icon: "f", label: "Meta / Social", color: "blue" };
	}
	if (source.includes("google") || medium.includes("cpc")) {
		return { icon: "G", label: "Google Ads", color: "sky" };
	}
	if (source.includes("email") || medium.includes("email")) {
		return { icon: "@", label: "Email", color: "violet" };
	}
	if (source.includes("qr") || medium.includes("offline")) {
		return { icon: "QR", label: "QR / Offline", color: "amber" };
	}

	return { icon: "UTM", label: "Canal pendiente", color: "zinc" };
}

function CampaignCard({ campaign, onArchive }) {
	const canEdit = Boolean(campaign.can_edit);
	const canArchive = Boolean(campaign.can_archive);
	const primaryLink = campaign.primary_link;
	const meta = channelMeta(primaryLink);

	return (
		<article className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900">
			<div className="grid gap-0 lg:grid-cols-[18rem_minmax(0,1fr)]">
				<div className="relative min-h-44 bg-zinc-100 dark:bg-zinc-800">
					{primaryLink?.hero_image ? (
						<img
							src={primaryLink.hero_image}
							alt={primaryLink.public_title || campaign.name}
							className="absolute inset-0 h-full w-full object-cover"
						/>
					) : (
						<div className="flex h-full min-h-44 items-center justify-center bg-lime-50 text-lime-900 dark:bg-lime-950/30 dark:text-lime-200">
							<div className="text-center">
								<LinkIcon className="mx-auto size-8" />
								<Text className="mt-2 text-sm font-semibold">
									Sin hero principal
								</Text>
							</div>
						</div>
					)}
					<div className="absolute left-3 top-3">
						<Badge color="famedic">Campaña</Badge>
					</div>
				</div>

				<div className="space-y-5 p-5">
					<div className="flex flex-wrap items-start justify-between gap-4">
						<div className="min-w-0 space-y-2">
							<div className="flex flex-wrap items-center gap-2">
								<Heading className="text-lg">{campaign.name}</Heading>
								<MarketingCampaignStatusBadge
									status={campaign.status}
									label={campaign.status_label}
								/>
							</div>
							{campaign.description && (
								<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
									{campaign.description}
								</Text>
							)}
							<Text className="text-sm text-zinc-500">
								Vigencia: {formatDateRange(campaign.starts_at, campaign.ends_at)}
							</Text>
						</div>
						<Text className="text-xs text-zinc-500">
							Actualizada {formatDateTime(campaign.updated_at || campaign.created_at)}
						</Text>
					</div>

					<div className="grid gap-3 sm:grid-cols-3">
						<div className="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
							<Text className="text-xs text-zinc-500">Enlaces hijos</Text>
							<Text className="mt-1 text-lg font-semibold">{campaign.links_count ?? 0}</Text>
						</div>
						<div className="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
							<Text className="text-xs text-zinc-500">Colecciones</Text>
							<Text className="mt-1 text-lg font-semibold">{campaign.collections_count ?? 0}</Text>
						</div>
						<div className="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700">
							<Text className="text-xs text-zinc-500">Enlace principal</Text>
							<div className="mt-1 flex items-center gap-2">
								<Badge color={meta.color}>
									<span className="font-mono">{meta.icon}</span>
									{meta.label}
								</Badge>
							</div>
						</div>
					</div>

					{primaryLink && (
						<div className="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800/70">
							<Text className="text-xs font-semibold uppercase text-zinc-500">
								Hijo destacado
							</Text>
							<div className="mt-2 flex flex-wrap items-center justify-between gap-3">
								<div className="min-w-0">
									<Text className="font-medium">
										{primaryLink.public_title || primaryLink.name}
									</Text>
									<Text className="mt-1 truncate font-mono text-sm text-zinc-500">
										/c/{primaryLink.slug}
									</Text>
								</div>
								<Badge color="slate">Enlace</Badge>
							</div>
						</div>
					)}

					<div className="flex flex-wrap justify-end gap-2">
						<Button
							href={route("admin.marketing-campaigns.show", campaign.id)}
							outline
						>
							<ArrowTopRightOnSquareIcon className="size-4" />
							Ver campaña
						</Button>
						{canEdit && (
							<Button
								href={route("admin.marketing-campaigns.edit", campaign.id)}
								outline
							>
								Editar
							</Button>
						)}
						{canArchive && (
							<Button type="button" color="red" onClick={() => onArchive(campaign)}>
								Archivar
							</Button>
						)}
					</div>
				</div>
			</div>
		</article>
	);
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
							href={route("admin.marketing-campaigns.create", {
								fresh: 1,
							})}
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
							<div className="space-y-4">
								{campaigns.data.map((campaign) => (
									<CampaignCard
										key={campaign.id}
										campaign={campaign}
										onArchive={setArchiveTarget}
									/>
								))}
							</div>
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
