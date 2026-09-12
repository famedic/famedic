import { useEffect, useState } from "react";
import { router, useForm } from "@inertiajs/react";
import {
	PlusIcon,
	PencilSquareIcon,
	ArchiveBoxIcon,
	ArrowTopRightOnSquareIcon,
	ClipboardDocumentIcon,
	FunnelIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import Card from "@/Components/Card";
import DateFilter from "@/Components/Filters/DateFilter";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import MarketingCampaignStatusBadge from "./Components/MarketingCampaignStatusBadge";
import MarketingCampaignLinksTable from "./Components/MarketingCampaignLinksTable";
import MarketingCampaignCollectionsTable from "./Components/MarketingCampaignCollectionsTable";
import MarketingCampaignChecklist from "./Components/MarketingCampaignChecklist";
import MarketingCampaignAttributedUsersTable from "./Components/MarketingCampaignAttributedUsersTable";

function normalizeStatus(status) {
	if (status == null) return "";
	if (typeof status === "object") {
		return String(status.value ?? status.name ?? "");
	}
	return String(status);
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
	if (!startsAt && !endsAt) return "Campaña permanente";
	if (!endsAt) return `Desde ${formatDateTime(startsAt)} · Sin expiración`;
	if (!startsAt) return `Hasta ${formatDateTime(endsAt)}`;

	return `${formatDateTime(startsAt)} — ${formatDateTime(endsAt)}`;
}

function formatNumber(value) {
	return new Intl.NumberFormat("es-MX").format(Number(value ?? 0));
}

function formatMoney(cents) {
	return new Intl.NumberFormat("es-MX", {
		style: "currency",
		currency: "MXN",
		maximumFractionDigits: 0,
	}).format(Number(cents ?? 0) / 100);
}

function formatPercent(value) {
	if (value === null || value === undefined) return "—";
	return `${Number(value).toLocaleString("es-MX", {
		maximumFractionDigits: 2,
	})}%`;
}

function utmLabel(value) {
	return value || "Sin dato";
}

function MetricCard({ label, value, detail }) {
	return (
		<Card className="p-4">
			<Text className="text-sm text-zinc-500">{label}</Text>
			<Text className="mt-1 text-2xl font-semibold">{value}</Text>
			{detail && (
				<Text className="mt-1 text-xs text-zinc-500">{detail}</Text>
			)}
		</Card>
	);
}

function RatePill({ label, value }) {
	return (
		<div className="rounded-lg border border-zinc-950/10 px-3 py-2 dark:border-white/10">
			<Text className="text-xs text-zinc-500">{label}</Text>
			<Text className="mt-1 text-sm font-semibold">
				{formatPercent(value)}
			</Text>
		</div>
	);
}

const TABS = [
	{ id: "summary", label: "Resumen" },
	{ id: "performance", label: "Rendimiento" },
	{ id: "links", label: "Enlaces" },
	{ id: "attributed-users", label: "Usuarios atribuidos" },
	{ id: "collections", label: "Colecciones" },
];

function activeTabFromLocation() {
	if (typeof window === "undefined") return "summary";
	const value = new URLSearchParams(window.location.search).get("tab");
	return TABS.some((tab) => tab.id === value) ? value : "summary";
}

function CampaignTabs({ activeTab, onChange }) {
	return (
		<div className="border-b border-zinc-200 dark:border-white/10">
			<div className="flex gap-1 overflow-x-auto">
				{TABS.map((tab) => (
					<button
						key={tab.id}
						type="button"
						aria-current={activeTab === tab.id ? "page" : undefined}
						onClick={() => onChange(tab.id)}
						className={`whitespace-nowrap border-b-2 px-4 py-3 text-sm font-semibold transition ${
							activeTab === tab.id
								? "border-famedic-light text-famedic-dark dark:text-lime-300"
								: "border-transparent text-zinc-500 hover:text-zinc-900 dark:hover:text-white"
						}`}
					>
						{tab.label}
					</button>
				))}
			</div>
		</div>
	);
}

function DashboardFilters({ filters, links, errors, processing, setData, submit, reset }) {
	return (
		<form onSubmit={submit} className="space-y-4">
			<div className="grid gap-4 md:grid-cols-5">
				<DateFilter
					label="Desde"
					value={filters.from}
					onChange={(value) => setData("from", value)}
					error={errors.from}
				/>
				<DateFilter
					label="Hasta"
					value={filters.to}
					onChange={(value) => setData("to", value)}
					error={errors.to}
				/>
				<Field>
					<Label>Enlace</Label>
					<select
						value={filters.link_id}
						onChange={(event) => setData("link_id", event.target.value)}
						className="block min-h-11 w-full rounded-lg border border-zinc-950/10 bg-white px-3 py-2 text-base text-zinc-950 shadow-sm sm:min-h-9 sm:text-sm dark:border-slate-800 dark:bg-slate-900 dark:text-white"
					>
						<option value="">Todos</option>
						{links.map((link) => (
							<option key={link.id} value={String(link.id)}>
								{link.name}
							</option>
						))}
					</select>
					{errors.link_id && (
						<ErrorMessage className="mt-3">{errors.link_id}</ErrorMessage>
					)}
				</Field>
				<Field>
					<Label>UTM source</Label>
					<Input
						value={filters.utm_source}
						onChange={(event) => setData("utm_source", event.target.value)}
						placeholder="facebook"
					/>
				</Field>
				<Field>
					<Label>UTM medium</Label>
					<Input
						value={filters.utm_medium}
						onChange={(event) => setData("utm_medium", event.target.value)}
						placeholder="cpc"
					/>
				</Field>
			</div>
			<div className="flex flex-wrap gap-2">
				<Button type="submit" outline disabled={processing}>
					<FunnelIcon className="size-4" />
					Aplicar filtros
				</Button>
				<Button type="button" plain onClick={reset} disabled={processing}>
					Limpiar
				</Button>
			</div>
		</form>
	);
}

function LinksBreakdownTable({ rows = [] }) {
	if (rows.length === 0) {
		return <Text className="text-sm text-zinc-500">Sin enlaces para desglosar.</Text>;
	}

	return (
		<div className="overflow-x-auto">
			<table className="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-white/10">
				<thead>
					<tr className="text-xs uppercase text-zinc-500">
						<th className="py-2 pr-4 font-medium">Enlace</th>
						<th className="px-4 py-2 text-right font-medium">Visitas</th>
						<th className="px-4 py-2 text-right font-medium">Visitantes</th>
						<th className="px-4 py-2 text-right font-medium">Registros</th>
						<th className="px-4 py-2 text-right font-medium">Compras</th>
						<th className="px-4 py-2 text-right font-medium">Revenue</th>
						<th className="py-2 pl-4 text-right font-medium">CVR</th>
					</tr>
				</thead>
				<tbody className="divide-y divide-zinc-100 dark:divide-white/5">
					{rows.map((row) => (
						<tr key={row.id}>
							<td className="py-3 pr-4">
								<div className="font-medium text-zinc-900 dark:text-white">{row.name}</div>
								<div className="text-xs text-zinc-500">/c/{row.slug}</div>
							</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.visits)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.unique_visitors)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.registrations)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.purchases)}</td>
							<td className="px-4 py-3 text-right">{formatMoney(row.revenue_cents)}</td>
							<td className="py-3 pl-4 text-right">{formatPercent(row.visit_to_purchase_rate)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
}

function UtmBreakdownTable({ rows = [] }) {
	if (rows.length === 0) {
		return <Text className="text-sm text-zinc-500">Sin UTMs en el periodo filtrado.</Text>;
	}

	return (
		<div className="overflow-x-auto">
			<table className="min-w-full divide-y divide-zinc-200 text-left text-sm dark:divide-white/10">
				<thead>
					<tr className="text-xs uppercase text-zinc-500">
						<th className="py-2 pr-4 font-medium">Source / medium</th>
						<th className="px-4 py-2 text-right font-medium">Visitas</th>
						<th className="px-4 py-2 text-right font-medium">Visitantes</th>
						<th className="px-4 py-2 text-right font-medium">Registros</th>
						<th className="px-4 py-2 text-right font-medium">Compras</th>
						<th className="py-2 pl-4 text-right font-medium">Revenue</th>
					</tr>
				</thead>
				<tbody className="divide-y divide-zinc-100 dark:divide-white/5">
					{rows.map((row) => (
						<tr key={`${row.source ?? "none"}-${row.medium ?? "none"}`}>
							<td className="py-3 pr-4 font-medium text-zinc-900 dark:text-white">
								{utmLabel(row.source)} / {utmLabel(row.medium)}
							</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.visits)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.unique_visitors)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.registrations)}</td>
							<td className="px-4 py-3 text-right">{formatNumber(row.purchases)}</td>
							<td className="py-3 pl-4 text-right">{formatMoney(row.revenue_cents)}</td>
						</tr>
					))}
				</tbody>
			</table>
		</div>
	);
}

async function copyText(text) {
	if (navigator.clipboard?.writeText) {
		await navigator.clipboard.writeText(text);
		return;
	}
	const input = document.createElement("textarea");
	input.value = text;
	document.body.appendChild(input);
	input.select();
	document.execCommand("copy");
	document.body.removeChild(input);
}

export default function MarketingCampaignsShow({
	campaign,
	links = [],
	collections = [],
	checklist = [],
	summary = {},
	analytics = {},
	analyticsFilters = {},
	attributedUsers = {},
	capabilities = {},
}) {
	const [archiveOpen, setArchiveOpen] = useState(false);
	const [archiving, setArchiving] = useState(false);
	const [copiedPrimary, setCopiedPrimary] = useState(false);
	const [copySelectorOpen, setCopySelectorOpen] = useState(false);
	const [activeTab, setActiveTab] = useState(activeTabFromLocation);
	const {
		data: filters,
		setData,
		get,
		processing,
		errors,
	} = useForm({
		from: analyticsFilters.from ?? "",
		to: analyticsFilters.to ?? "",
		link_id: analyticsFilters.link_id ?? "",
		utm_source: analyticsFilters.utm_source ?? "",
		utm_medium: analyticsFilters.utm_medium ?? "",
	});

	const status = normalizeStatus(campaign.status);
	const isArchived =
		Boolean(campaign.is_archived) || status === "archived";

	const canEdit =
		!isArchived &&
		Boolean(capabilities.canEdit ?? capabilities.edit);
	const canArchive =
		!isArchived &&
		Boolean(
			capabilities.canArchive ??
				capabilities.archive ??
				capabilities.edit,
		);
	const canCreateLink =
		!isArchived &&
		Boolean(
			capabilities.canCreateLink ??
				capabilities.create ??
				capabilities.edit,
		);
	const canCreateCollection =
		!isArchived &&
		Boolean(
			capabilities.canCreateCollection ??
				capabilities.create ??
				capabilities.edit,
		);

	const campaignLinks = links.length ? links : campaign.links || [];
	const campaignCollections = collections.length
		? collections
		: campaign.collections || [];
	const primaryLink = summary.primary_link || campaignLinks[0] || null;
	const createLinkHref = canCreateLink
		? route("admin.marketing-campaigns.links.create", campaign.id)
		: null;
	const totals = analytics.totals ?? {};
	const firstTouch = analytics.first_touch ?? {};

	useEffect(() => {
		const syncTab = () => setActiveTab(activeTabFromLocation());
		window.addEventListener("popstate", syncTab);

		return () => window.removeEventListener("popstate", syncTab);
	}, []);

	const changeTab = (tab) => {
		setActiveTab(tab);
		if (typeof window !== "undefined") {
			const url = new URL(window.location.href);
			if (tab === "summary") {
				url.searchParams.delete("tab");
			} else {
				url.searchParams.set("tab", tab);
			}
			window.history.pushState({}, "", url);
		}
	};

	const confirmArchive = () => {
		if (archiving) return;
		setArchiving(true);
		router.post(
			route("admin.marketing-campaigns.archive", campaign.id),
			{},
			{
				preserveScroll: true,
				onFinish: () => {
					setArchiving(false);
					setArchiveOpen(false);
				},
			},
		);
	};

	const copyFullLink = async (link) => {
		const url = link?.full_url || link?.public_url;
		if (!url) return;
		await copyText(url);
		setCopiedPrimary(true);
		setCopySelectorOpen(false);
		setTimeout(() => setCopiedPrimary(false), 2000);
	};

	const handlePrimaryCopy = async () => {
		if (campaignLinks.length > 1) {
			setCopySelectorOpen((open) => !open);
			return;
		}

		await copyFullLink(primaryLink);
	};

	const submitFilters = (event) => {
		event.preventDefault();
		get(route("admin.marketing-campaigns.show", campaign.id), {
			preserveScroll: true,
			preserveState: true,
		});
	};

	const resetFilters = () => {
		router.get(
			route("admin.marketing-campaigns.show", campaign.id),
			{},
			{ preserveScroll: true },
		);
	};

	return (
		<AdminLayout title={campaign.name}>
			<div className="space-y-10">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-3">
						<Heading>{campaign.name}</Heading>
						{campaign.description && (
							<Text className="max-w-2xl text-zinc-600 dark:text-zinc-400">
								{campaign.description}
							</Text>
						)}
						<div className="flex flex-wrap items-center gap-3">
							<MarketingCampaignStatusBadge
								status={campaign.status}
								label={campaign.status_label}
							/>
							{isArchived && (
								<Badge color="zinc">
									Campaña archivada · Solo lectura
								</Badge>
							)}
							<Text className="text-sm text-zinc-500">
								Vigencia: {formatDateRange(campaign.starts_at, campaign.ends_at)}
							</Text>
						</div>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button
							href={route("admin.marketing-campaigns.index")}
							outline
						>
							Volver
						</Button>
						{primaryLink?.public_url && (
							<>
								<Button
									type="button"
									outline
									onClick={() =>
										window.open(
											primaryLink.public_url,
											"_blank",
											"noopener,noreferrer",
										)
									}
								>
									<ArrowTopRightOnSquareIcon className="size-4" />
									Abrir landing
								</Button>
								<div className="relative">
									<Button type="button" outline onClick={handlePrimaryCopy}>
										<ClipboardDocumentIcon className="size-4" />
										{copiedPrimary ? "URL completa copiada" : "Copiar URL con UTMs"}
									</Button>
									{copySelectorOpen && campaignLinks.length > 1 && (
										<div className="absolute right-0 z-20 mt-2 w-80 rounded-xl border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
											<Text className="text-sm font-semibold">Selecciona un enlace</Text>
											<div className="mt-3 space-y-2">
												{campaignLinks.map((link) => (
													<button
														key={link.id}
														type="button"
														className="block w-full rounded-lg px-3 py-2 text-left hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-lime dark:hover:bg-zinc-800"
														onClick={() => copyFullLink(link)}
													>
														<span className="block text-sm font-semibold text-zinc-900 dark:text-white">
															{link.name}
														</span>
														<span className="mt-1 block truncate font-mono text-xs text-zinc-500">
															{link.full_url || link.public_url}
														</span>
													</button>
												))}
											</div>
										</div>
									)}
								</div>
							</>
						)}
						{canEdit && (
							<Button
								href={route(
									"admin.marketing-campaigns.edit",
									campaign.id,
								)}
								outline
							>
								<PencilSquareIcon className="size-4" />
								Editar campaña
							</Button>
						)}
						{canCreateLink && (
							<Button href={createLinkHref} color="lime">
								<PlusIcon className="size-4" />
								Nuevo enlace
							</Button>
						)}
						{canArchive && (
							<Button
								type="button"
								color="red"
								onClick={() => setArchiveOpen(true)}
							>
								<ArchiveBoxIcon className="size-4" />
								Archivar
							</Button>
						)}
					</div>
				</div>

				<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
					<MetricCard label="Enlaces" value={formatNumber(summary.links_count ?? campaign.links_count ?? 0)} />
					<MetricCard label="Colecciones" value={formatNumber(summary.collections_count ?? campaign.collections_count ?? 0)} />
					<MetricCard label="Productos configurados" value={formatNumber(summary.configured_products_count ?? 0)} />
					<MetricCard label="Completitud" value={`${summary.completeness_percent ?? 0}%`} />
				</div>

				<CampaignTabs activeTab={activeTab} onChange={changeTab} />

				{activeTab === "summary" && (
					<section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_24rem]">
						<Card className="space-y-4 p-5">
							<Subheading>Resumen</Subheading>
							<div className="grid gap-4 sm:grid-cols-2">
								<MetricCard label="Visitas" value={formatNumber(totals.visits)} />
								<MetricCard label="Compras" value={formatNumber(totals.purchases)} />
								<MetricCard label="Ingresos" value={formatMoney(totals.revenue_cents)} />
								<MetricCard label="Completitud" value={`${summary.completeness_percent ?? 0}%`} />
							</div>
							{primaryLink?.public_url && (
								<div className="rounded-lg bg-lime-50 p-4 dark:bg-lime-950/30">
									<Text className="font-medium">Landing principal</Text>
									<Text className="mt-1 break-all font-mono text-sm text-zinc-600 dark:text-zinc-300">
										{primaryLink.full_url || primaryLink.public_url}
									</Text>
								</div>
							)}
						</Card>
						<MarketingCampaignChecklist items={checklist} />
					</section>
				)}

				{activeTab === "performance" && (
				<section className="space-y-5">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<div>
							<Subheading>Rendimiento</Subheading>
							<Text className="mt-1 text-sm text-zinc-500">
								Compras de laboratorio atribuidas a esta campaña.
							</Text>
							<Text className="mt-1 text-xs text-zinc-500">
								Registros cuenta identificaciones guardadas desde esta versión.
							</Text>
						</div>
						<Badge color="zinc">
							{analytics.period?.timezone ?? "Timezone app"}
						</Badge>
					</div>
					<Card className="space-y-5 p-4">
						<DashboardFilters
							filters={filters}
							links={campaignLinks}
							errors={errors}
							processing={processing}
							setData={setData}
							submit={submitFilters}
							reset={resetFilters}
						/>
						<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
							<MetricCard label="Visitas" value={formatNumber(totals.visits)} />
							<MetricCard label="Visitantes únicos" value={formatNumber(totals.unique_visitors)} />
							<MetricCard label="Registros" value={formatNumber(totals.registrations)} />
							<MetricCard label="Compradores" value={formatNumber(totals.buyers)} />
							<MetricCard label="Compras" value={formatNumber(totals.purchases)} />
							<MetricCard label="Revenue" value={formatMoney(totals.revenue_cents)} />
							<MetricCard label="Ticket promedio" value={totals.average_ticket_cents == null ? "—" : formatMoney(totals.average_ticket_cents)} />
							<MetricCard label="Compras first-touch" value={formatNumber(firstTouch.purchases)} detail={formatMoney(firstTouch.revenue_cents)} />
						</div>
						<div className="grid gap-3 md:grid-cols-3">
							<RatePill label="Visitante a registro" value={totals.visit_to_registration_rate} />
							<RatePill label="Visitante a compra" value={totals.visit_to_purchase_rate} />
							<RatePill label="Registro a compra" value={totals.registration_to_purchase_rate} />
						</div>
					</Card>
					<div className="grid gap-5 xl:grid-cols-2">
						<Card className="p-4">
							<Subheading>Por enlace</Subheading>
							<div className="mt-4">
								<LinksBreakdownTable rows={analytics.links ?? []} />
							</div>
						</Card>
						<Card className="p-4">
							<Subheading>Por UTM</Subheading>
							<div className="mt-4">
								<UtmBreakdownTable rows={analytics.utm_breakdown ?? []} />
							</div>
						</Card>
					</div>
				</section>
				)}

				{activeTab === "links" && (
				<section className="space-y-4">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<Subheading>Enlaces</Subheading>
						{canCreateLink && (
							<Button href={createLinkHref} color="lime">
								<PlusIcon />
								Nuevo enlace
							</Button>
						)}
					</div>
					<MarketingCampaignLinksTable
						campaignId={campaign.id}
						links={campaignLinks}
						canEdit={canEdit}
						createHref={createLinkHref}
					/>
				</section>
				)}

				{activeTab === "attributed-users" && (
				<section className="space-y-4">
					<div>
						<Subheading>Usuarios atribuidos</Subheading>
						<Text className="mt-1 text-sm text-zinc-500">
							Relaciona registros identificados con campaign attribution, enlaces y conversiones.
						</Text>
					</div>
					<MarketingCampaignAttributedUsersTable
						campaignId={campaign.id}
						links={campaignLinks}
						attributedUsers={attributedUsers}
					/>
				</section>
				)}

				{activeTab === "collections" && (
				<section className="space-y-4">
					<div className="flex flex-wrap items-end justify-between gap-3">
						<div>
							<Subheading>Colecciones</Subheading>
							<Text className="mt-1 text-sm text-zinc-500">
								Solo necesitas una colección cuando reutilizarás
								un grupo de estudios.
							</Text>
						</div>
						{canCreateCollection && (
							<Button
								href={route(
									"admin.marketing-campaigns.collections.create",
									campaign.id,
								)}
								color="lime"
							>
								<PlusIcon />
								Crear colección
							</Button>
						)}
					</div>
					<MarketingCampaignCollectionsTable
						campaignId={campaign.id}
						collections={campaignCollections}
						canEdit={canEdit}
						createHref={
							canCreateCollection
								? route(
										"admin.marketing-campaigns.collections.create",
										campaign.id,
									)
								: null
						}
					/>
				</section>
				)}
			</div>

			<DeleteConfirmationModal
				isOpen={archiveOpen}
				close={() => setArchiveOpen(false)}
				title="Archivar campaña"
				description={`¿Archivar la campaña “${campaign.name}”? Después de archivarla, la campaña quedará disponible únicamente para consulta.`}
				processing={archiving}
				destroy={confirmArchive}
				confirmLabel="Archivar"
			/>
		</AdminLayout>
	);
}
