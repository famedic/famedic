import { router, useForm } from "@inertiajs/react";
import axios from "axios";
import { useEffect, useMemo, useState } from "react";
import { ArrowDownTrayIcon, ArrowsUpDownIcon, EyeIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Dialog, DialogActions, DialogBody, DialogTitle } from "@/Components/Catalyst/dialog";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";
import { Textarea } from "@/Components/Catalyst/textarea";

const quickFilters = [
	["all", "Todos"],
	["altas", "Altas"],
	["bajas", "Bajas"],
	["sin_accion", "Sin acción"],
	["pending", "Pendientes"],
	["processed", "Procesados"],
	["blocked", "Bloqueados"],
	["errors", "Errores"],
	["email_different", "Correo diferente"],
	["without_number", "Sin noCredito"],
	["not_found", "No encontrados FAMEDIC"],
	["not_found_murguia", "No encontrados Murguía"],
	["possible_duplicates", "Posibles duplicados"],
];

const correctiveActions = [
	["update-email", "Actualizar email"],
	["link-odessa-account", "Vincular cuenta ODESSA"],
	["create-membership", "Crear membresía"],
	["retry-murguia-sync", "Reintentar Murguía"],
	["activate-murguia-membership", "Alta Murguía"],
	["deactivate-murguia-membership", "Baja Murguía"],
];

const operationTabs = [
	["summary", "Resumen", "all"],
	["altas", "Altas", "altas"],
	["bajas", "Bajas", "bajas"],
	["exact", "Coincidencias", "exact_matches"],
	["possible", "Posibles coincidencias", "possible_matches"],
	["no_match", "Sin coincidencia", "not_found"],
	["errors", "Errores / datos incompletos", "data_errors"],
	["history", "Acciones / historial", "history"],
];

const defaultFilters = {
	sourceAction: "",
	actionStatus: "",
	murguiaStatus: "",
	matchType: "",
	flag: "",
	credit: "",
	emailDifferent: "",
	duplicate: "",
	actionState: "",
	reviewStatus: "",
};

const exactMatchTypes = [
	"MATCH_CONFIRMED_ODESSA_ID",
	"MATCH_CONFIRMED_COMPANY_PARTNER",
	"MATCH_CONFIRMED_MEMBERSHIP",
	"MATCH_CONFIRMED_EMAIL",
];

const duplicateFlags = [
	"POSSIBLE_DUPLICATE_PERSON",
	"POSSIBLE_EXISTING_USER",
	"DUPLICATE_ODESSA_ID",
	"DUPLICATE_COMPANY_PARTNER",
	"DUPLICATE_MEMBERSHIP_IDENTIFIER",
];

export default function Show({ preview, successMessage, canReview, canActions = {}, errors = {} }) {
	const [selectedRow, setSelectedRow] = useState(null);
	const [quickFilter, setQuickFilter] = useState("all");
	const [activeTab, setActiveTab] = useState("summary");
	const [search, setSearch] = useState("");
	const [filters, setFilters] = useState(defaultFilters);
	const [sort, setSort] = useState({ key: "priority", direction: "asc" });

	const rows = preview?.rows || [];
	const activeTabFilter = operationTabs.find(([key]) => key === activeTab)?.[2] || "all";
	const filteredRows = useMemo(
		() => sortRows(rows.filter((row) => {
			if (!matchesQuickFilter(row, quickFilter)) return false;
			if (!matchesQuickFilter(row, activeTabFilter)) return false;
			if (search && !row.search_text?.includes(search.toLowerCase())) return false;
			if (!matchesAdvancedFilters(row, filters)) return false;
			return true;
		}), sort),
		[activeTabFilter, filters, quickFilter, rows, search, sort],
	);
	const tabCounts = useMemo(
		() => Object.fromEntries(operationTabs.map(([key,, filter]) => [
			key,
			rows.filter((row) => matchesQuickFilter(row, filter)).length,
		])),
		[rows],
	);
	const currentColumns = columnsForTab(activeTab);
	const updateFilter = (key, value) => setFilters((current) => ({ ...current, [key]: value }));
	const requestSort = (key) => setSort((current) => ({
		key,
		direction: current.key === key && current.direction === "asc" ? "desc" : "asc",
	}));

	return (
		<AdminLayout title="Conciliación ODESSA">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>Conciliación ODESSA #{preview.meta.id}</Heading>
						<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
							Reporte: {preview.meta.source_filename} · Murguía:{" "}
							{preview.meta.murguia_filename || "No cargado"} · Ejecutada por:{" "}
							{preview.meta.uploaded_by || "—"}
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						<Button href={route("admin.odessa.reconciliations.index")} outline>
							Historial
						</Button>
						<a
							href={preview.export.url}
							className="relative inline-flex items-center justify-center gap-x-2 rounded-lg border border-transparent bg-famedic-dark px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-famedic-dark/90 dark:bg-famedic-lime dark:text-famedic-darker"
						>
							<ArrowDownTrayIcon className="size-4" />
							Descargar reporte ejecutivo
						</a>
					</div>
				</div>
				<Text className="text-sm text-zinc-500">
					Último reporte generado: {preview.meta.generated_at || "—"}
				</Text>

				{successMessage ? <Notice>{successMessage}</Notice> : null}
				{errors.export ? <Notice color="red">{errors.export}</Notice> : null}
				{errors.action ? <Notice color="red">{errors.action}</Notice> : null}

				<Summary summary={preview.summary} />
				<OperationViews views={preview.operation_views} />

				<div className="space-y-3">
					<TabBar activeTab={activeTab} counts={tabCounts} onChange={(tab) => {
						setActiveTab(tab);
						setQuickFilter("all");
					}} />
					<div className="flex flex-wrap gap-2">
						{quickFilters.map(([key, label]) => (
							<Button
								key={key}
								type="button"
								outline={quickFilter !== key}
								onClick={() => setQuickFilter(key)}
								className="text-xs"
							>
								{label} ({preview.filter_counts?.[key] ?? 0})
							</Button>
						))}
					</div>
					<div className="grid gap-3 xl:grid-cols-[1.2fr_repeat(4,minmax(10rem,1fr))]">
						<InputGroup>
							<Input
								value={search}
								onChange={(event) => setSearch(event.target.value)}
								placeholder="Buscar nombre, email, ID ODESSA, socio, user, customer o membresía"
							/>
						</InputGroup>
						<Select value={filters.sourceAction} onChange={(event) => updateFilter("sourceAction", event.target.value)}>
							<option value="">source_action</option>
							{["ALTA", "BAJA", "NONE", "UNKNOWN", ...(preview.filters.source_actions || [])].filter(uniqueOption).map((value) => (
								<option key={value} value={value}>
									{value}
								</option>
							))}
						</Select>
						<Select value={filters.actionStatus} onChange={(event) => updateFilter("actionStatus", event.target.value)}>
							<option value="">source_action_status</option>
							{(preview.filters.source_action_statuses || []).map((value) => (
								<option key={value} value={value}>{actionStatusLabel(value)}</option>
							))}
						</Select>
						<Select value={filters.murguiaStatus} onChange={(event) => updateFilter("murguiaStatus", event.target.value)}>
							<option value="">murguia_status</option>
							{(preview.filters.murguia_statuses || []).map((value) => (
								<option key={value} value={value}>{murguiaLabel(value)}</option>
							))}
						</Select>
						<Select value={filters.matchType} onChange={(event) => updateFilter("matchType", event.target.value)}>
							<option value="">match_status</option>
							{(preview.filters.match_types || []).map((value) => (
								<option key={value} value={value}>{matchTypeLabel(value)}</option>
							))}
						</Select>
					</div>
					<div className="grid gap-3 md:grid-cols-2 xl:grid-cols-6">
						<Select value={filters.flag} onChange={(event) => updateFilter("flag", event.target.value)}>
							<option value="">data_quality_flags</option>
							{(preview.filters.flags || []).map((value) => (
								<option key={value} value={value}>{flagLabel(value)}</option>
							))}
						</Select>
						<Select value={filters.credit} onChange={(event) => updateFilter("credit", event.target.value)}>
							<option value="">noCredito</option>
							<option value="with">Con noCredito</option>
							<option value="without">Sin noCredito</option>
						</Select>
						<Select value={filters.emailDifferent} onChange={(event) => updateFilter("emailDifferent", event.target.value)}>
							<option value="">Email comparado</option>
							<option value="yes">Email distinto</option>
							<option value="no">Email igual / sin alerta</option>
						</Select>
						<Select value={filters.duplicate} onChange={(event) => updateFilter("duplicate", event.target.value)}>
							<option value="">Duplicados</option>
							<option value="yes">Con posible duplicado</option>
							<option value="no">Sin duplicado probable</option>
						</Select>
						<Select value={filters.actionState} onChange={(event) => updateFilter("actionState", event.target.value)}>
							<option value="">Acción</option>
							<option value="available">Disponible</option>
							<option value="blocked">Bloqueada</option>
							<option value="executed">Ejecutada</option>
							<option value="failed">Fallida</option>
						</Select>
						<Select value={filters.reviewStatus} onChange={(event) => updateFilter("reviewStatus", event.target.value)}>
							<option value="">Revisión</option>
							{(preview.review_statuses || preview.filters.review_statuses || []).map((value) => (
								<option key={value} value={value}>{reviewLabel(value)}</option>
							))}
						</Select>
					</div>
				</div>

				<OperationalTable
					columns={currentColumns}
					rows={filteredRows}
					sort={sort}
					onSort={requestSort}
					onSelect={setSelectedRow}
				/>

				<DetailDialog
					row={selectedRow}
					canReview={canReview}
					canActions={canActions}
					onClose={() => setSelectedRow(null)}
				/>
			</div>
		</AdminLayout>
	);
}

function TabBar({ activeTab, counts, onChange }) {
	return (
		<div className="flex gap-2 overflow-x-auto border-b border-zinc-200 pb-2 dark:border-zinc-800">
			{operationTabs.map(([key, label]) => (
				<button
					key={key}
					type="button"
					onClick={() => onChange(key)}
					className={`shrink-0 rounded-lg px-3 py-2 text-sm font-medium ${activeTab === key ? "bg-famedic-dark text-white dark:bg-famedic-lime dark:text-famedic-darker" : "bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200"}`}
				>
					{label} <span className="tabular-nums opacity-75">({counts[key] ?? 0})</span>
				</button>
			))}
		</div>
	);
}

function OperationalTable({ columns, rows, sort, onSort, onSelect }) {
	return (
		<div className="max-h-[72vh] overflow-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
			<Table dense className="[&_td]:align-top">
				<TableHead className="sticky top-0 z-10 bg-white shadow-sm dark:bg-zinc-900">
					<TableRow>
						{columns.map((column) => (
							<TableHeader key={column.key} className={column.className || ""}>
								<button
									type="button"
									onClick={() => column.sortable === false ? null : onSort(column.key)}
									className="flex w-full items-center gap-1 text-left"
								>
									<span>{column.label}</span>
									{column.sortable === false ? null : (
										<ArrowsUpDownIcon className={`size-3 ${sort.key === column.key ? "text-famedic-dark dark:text-famedic-lime" : "text-zinc-400"}`} />
									)}
								</button>
							</TableHeader>
						))}
						<TableHeader className="sticky right-0 bg-white text-right dark:bg-zinc-900" />
					</TableRow>
				</TableHead>
				<TableBody>
					{rows.length === 0 ? (
						<TableRow>
							<TableCell colSpan={columns.length + 1} className="py-10 text-center text-zinc-500">
								Sin resultados para los filtros seleccionados.
							</TableCell>
						</TableRow>
					) : (
						rows.map((row) => (
							<TableRow key={row.id}>
								{columns.map((column) => (
									<TableCell key={column.key} className={column.cellClassName || "min-w-36"}>
										{column.render(row)}
									</TableCell>
								))}
								<TableCell className="sticky right-0 bg-white text-right dark:bg-zinc-900">
									<Button type="button" outline onClick={() => onSelect(row)}>
										<EyeIcon data-slot="icon" />
										Ver
									</Button>
								</TableCell>
							</TableRow>
						))
					)}
				</TableBody>
			</Table>
		</div>
	);
}

function columnsForTab(tab) {
	const common = [
		col("source.action", "Acción", (row) => <SourceActionBadge action={row.source.action} />),
		col("source.name", "Colaborador", (row) => <PersonCell row={row} />, "min-w-72"),
		col("source.email", "Email ODESSA", (row) => <WrapValue value={row.source.email} breakAll />, "min-w-56"),
		col("famedic.email", "Email FAMEDIC", (row) => <WrapValue value={row.famedic.email} breakAll />, "min-w-56"),
		col("source.employee", "# empleado", (row) => row.source.employee || "—"),
		col("source.odessa_id", "ID ODESSA", (row) => <WrapValue value={row.source.odessa_id || row.famedic.odessa_id} breakAll />),
		col("membership.identifier", "noCredito", (row) => <CreditNumber value={row.membership.identifier} />, "min-w-40"),
		col("murguia.status", "Murguía", (row) => <Badge color={murguiaColor(row.murguia.status)}>{murguiaLabel(row.murguia.status)}</Badge>),
		col("dimensions.source_action_status", "Estado acción", (row) => <ActionStatusCell row={row} />, "min-w-52"),
		col("review.status", "Revisión", (row) => <Badge color={reviewColor(row.review?.status)}>{reviewLabel(row.review?.status)}</Badge>),
		col("evidence", "Evidencia", (row) => <EvidenceCell row={row} />, "min-w-80"),
		col("alerts", "Alertas", (row) => <AlertCell row={row} />, "min-w-56"),
	];

	if (tab === "bajas") {
		return [
			common[1],
			common[2],
			common[3],
			common[4],
			common[5],
			common[6],
			col("membership.status", "Estatus FAMEDIC", (row) => <Badge color={membershipColor(row.membership.status)}>{row.membership.status_label}</Badge>),
			common[7],
			common[0],
			common[8],
			common[9],
			col("lastAction", "Último resultado", (row) => lastActionLabel(row), "min-w-56"),
			common[10],
			common[11],
		];
	}

	if (tab === "altas") {
		return [
			common[1],
			common[2],
			common[3],
			common[4],
			common[5],
			common[6],
			col("famedic.customer_id", "Customer", (row) => <ExistenceBadge exists={row.famedic.customer_exists} />),
			col("famedic.odessa_account_id", "Cuenta ODESSA", (row) => <ExistenceBadge exists={row.famedic.odessa_exists} />),
			col("membership.subscription_id", "Membresía", (row) => <ExistenceBadge exists={Boolean(row.membership.subscription_id)} />),
			common[7],
			common[0],
			common[8],
			common[10],
			common[11],
		];
	}

	if (["exact", "possible"].includes(tab)) {
		return [
			common[1],
			col("match.type", "Match", (row) => <MatchCell row={row} />),
			col("compare", "ODESSA vs FAMEDIC vs Murguía", (row) => <ComparisonCell row={row} />, "min-w-[32rem]", false),
			col("critical", "Diferencias críticas", (row) => <CriticalDiffs row={row} />, "min-w-64"),
			common[10],
			common[11],
		];
	}

	if (tab === "history") {
		return [
			common[1],
			common[0],
			common[8],
			common[9],
			col("history", "Acciones ejecutadas / historial", (row) => (
				<ListInline items={row.actions.items.map((action) => actionSummary(action))} empty="Sin acciones" />
			), "min-w-[28rem]"),
			common[10],
		];
	}

	return common;
}

function col(key, label, render, cellClassName = "min-w-36", sortable = true) {
	return { key, label, render, cellClassName, sortable };
}

function PersonCell({ row }) {
	return (
		<div className="min-w-0">
			<div className="font-medium">{row.source.name || row.famedic.name || "Sin nombre"}</div>
			<div className="mt-1 text-xs text-zinc-500">Hoja {row.source.sheet || "—"} · fila {row.source.row || "—"}</div>
			<div className="mt-1 flex flex-wrap gap-1">
				<Badge color={matchColor(row.match.type)}>{matchTypeLabel(row.match.type)}</Badge>
				{row.match.confidence ? <Badge color="sky">{row.match.confidence}</Badge> : null}
			</div>
		</div>
	);
}

function ActionStatusCell({ row }) {
	return (
		<div className="space-y-1">
			<Badge color={actionStatusColor(row.dimensions.source_action_status)}>
				{actionStatusLabel(row.dimensions.source_action_status)}
			</Badge>
			{row.dimensions.blocked_reasons?.length ? (
				<div className="break-words text-xs text-zinc-500">{row.dimensions.blocked_reasons.join(", ")}</div>
			) : null}
		</div>
	);
}

function EvidenceCell({ row }) {
	return <ListInline items={row.match.evidence} empty="Sin evidencia" />;
}

function AlertCell({ row }) {
	return (
		<div className="space-y-1">
			<FlagList flags={row.dimensions.flags || []} labels={row.dimensions.flag_labels || []} />
			<CriticalDiffs row={row} compact />
		</div>
	);
}

function MatchCell({ row }) {
	return (
		<div className="space-y-1">
			<Badge color={matchColor(row.match.type)}>{matchTypeLabel(row.match.type)}</Badge>
			<div className="text-xs text-zinc-500">Confianza: {row.match.confidence || "—"}</div>
			{row.match.candidate_count ? <Badge color="amber">{row.match.candidate_count} candidatos</Badge> : null}
		</div>
	);
}

function ComparisonCell({ row }) {
	const compared = [
		["Nombre", row.source.name, row.famedic.name, murguiaLabel(row.murguia.status)],
		["Email", row.source.email, row.famedic.email, row.murguia.last_log_email],
		["ID ODESSA", row.source.odessa_id, row.famedic.odessa_id, "—"],
		["# empleado", row.source.employee, row.famedic.employee, "—"],
		["noCredito", row.membership.identifier, row.membership.identifier, row.murguia.exists_in_report],
	];
	return (
		<div className="grid min-w-0 grid-cols-[8rem_repeat(3,minmax(0,1fr))] gap-1 text-xs">
			<div className="font-semibold text-zinc-500">Campo</div>
			<div className="font-semibold text-zinc-500">ODESSA</div>
			<div className="font-semibold text-zinc-500">FAMEDIC</div>
			<div className="font-semibold text-zinc-500">Murguía</div>
			{compared.flatMap(([label, odessa, famedic, murguia]) => [
				<div key={`${label}-l`} className="text-zinc-500">{label}</div>,
				<WrapValue key={`${label}-o`} value={odessa} breakAll />,
				<WrapValue key={`${label}-f`} value={famedic} breakAll />,
				<WrapValue key={`${label}-m`} value={murguia} breakAll />,
			])}
		</div>
	);
}

function CriticalDiffs({ row, compact = false }) {
	const diffs = criticalDiffs(row);
	if (!diffs.length) return compact ? null : <span className="text-xs text-zinc-400">Sin diferencias críticas</span>;
	return (
		<div className="flex flex-wrap gap-1">
			{diffs.slice(0, compact ? 3 : 8).map((diff) => <Badge key={diff} color="orange">{diff}</Badge>)}
		</div>
	);
}

function WrapValue({ value, breakAll = false }) {
	return <span className={`block min-w-0 whitespace-normal ${breakAll ? "break-all" : "[overflow-wrap:anywhere]"}`}>{value || "—"}</span>;
}

function ListInline({ items, empty = "Sin datos" }) {
	if (!items?.length) return <span className="text-xs text-zinc-400">{empty}</span>;
	return (
		<ul className="space-y-1 text-xs">
			{items.slice(0, 5).map((item, index) => (
				<li key={`${item}-${index}`} className="whitespace-normal break-words [overflow-wrap:anywhere]">{item}</li>
			))}
			{items.length > 5 ? <li className="text-zinc-500">+{items.length - 5} más</li> : null}
		</ul>
	);
}

function DetailDialog({ row, canReview, canActions, onClose }) {
	if (!row) return null;
	return (
		<Dialog open={Boolean(row)} onClose={onClose} size="5xl">
			<DialogTitle>{row.source.name || "Detalle de colaborador"}</DialogTitle>
			<DialogBody className="max-h-[75vh] overflow-y-auto">
				<div className="grid gap-6 lg:grid-cols-2">
					<DetailSection title="ODESSA / Excel" items={[
						["Acción solicitada", row.source.action],
						["Empresa", row.source.company],
						["Empleado", row.source.employee],
						["Color detectado", row.source.action_color],
						["Nombre", row.source.name],
						["Nacimiento", row.source.birth_date],
						["Correo", row.source.email],
						["ID ODESSA", row.source.odessa_id],
						["Hoja / fila", `${row.source.sheet} / ${row.source.row}`],
					]} />
					<DetailSection title="FAMEDIC" items={[
						["User ID", row.famedic.user_id],
						["Customer ID", row.famedic.customer_id],
						["Nombre", row.famedic.name],
						["Correo", row.famedic.email],
						["Fecha nacimiento", row.famedic.birth_date],
					]} links={[
						row.famedic.customer_url ? ["Ver cliente", row.famedic.customer_url] : null,
						row.famedic.user_url ? ["Ver usuario", row.famedic.user_url] : null,
					]} />
					<DetailSection title="Cuenta ODESSA" items={[
						["Existe", row.famedic.odessa_exists ? "Sí" : "No"],
						["Odessa Account ID", row.famedic.odessa_account_id],
						["ID ODESSA DB", row.famedic.odessa_id],
						["Empresa interna", row.famedic.company_internal_id],
						["Empresa DB", row.famedic.company],
						["Socio", row.famedic.employee],
						["Estado", row.dimensions.account_status],
					]} />
					<DetailSection title="Membresía" items={[
						["noCredito", row.membership.identifier || "SIN noCredito"],
						["Subscription ID", row.membership.subscription_id],
						["Estado membresía", row.membership.status_label],
						["Vigencia", `${row.membership.start_date || "—"} / ${row.membership.end_date || "—"}`],
						["Última sincronización", row.membership.last_sync],
					]} />
					<DetailSection title="Murguía" items={[
						["Existe en reporte", row.murguia.exists_in_report],
						["Murguía", murguiaLabel(row.murguia.status)],
						["Auditoría", row.murguia.audit_status],
						["Último sync", row.membership.last_sync],
						["Última acción", row.murguia.last_log_action],
						["Último resultado", row.murguia.last_log_status],
						["Último error", row.murguia.last_error],
					]} />
					<DetailSection title="Discrepancias" items={[
						["Tipo match", row.match.type],
						["Confianza", row.match.confidence],
						["Candidatos", row.match.candidate_count],
						["Estado técnico", row.dimensions.final_status],
						["Estado acción", actionStatusLabel(row.dimensions.source_action_status)],
						["Razón auditoría", row.dimensions.audit_reason],
					]} />
					<DetailSection title="Acción solicitada" items={[
						["Acción ODESSA", row.source.action],
						["Estado operativo", actionStatusLabel(row.dimensions.source_action_status)],
						["Acción correctiva", suggestedAction(row)],
					]} />
				</div>

				<div className="mt-6 grid gap-4 lg:grid-cols-2">
					<section className="rounded-lg border border-zinc-200 p-4 lg:col-span-2 dark:border-zinc-700">
						<Subheading level={3}>Comparativo ODESSA vs FAMEDIC vs Murguía</Subheading>
						<div className="mt-3 overflow-x-auto">
							<ComparisonCell row={row} />
						</div>
						<div className="mt-3">
							<CriticalDiffs row={row} />
						</div>
					</section>
					<ListPanel title="Evidencia" items={row.match.evidence} />
					<ListPanel title="Candidatos posibles" items={row.match.candidates || []} empty="Sin candidatos alternativos" />
					<ListPanel title="Razones de bloqueo" items={row.dimensions.blocked_reasons} empty="Sin bloqueos" />
					<ListPanel title="Discrepancias" items={row.dimensions.flag_labels?.length ? row.dimensions.flag_labels : row.dimensions.flags} empty="Sin alertas" />
					<ListPanel title="Notas del motor" items={row.review_notes} />
					<ListPanel title="Historial correctivo" items={row.actions.items.map((action) => actionSummary(action))} empty="Sin acciones correctivas" />
					<ActionPanel row={row} canActions={canActions} />
					<ReviewPanel row={row} canReview={canReview} />
				</div>
			</DialogBody>
			<DialogActions>
				<Button type="button" outline onClick={onClose}>
					Cerrar
				</Button>
			</DialogActions>
		</Dialog>
	);
}

function ActionPanel({ row, canActions }) {
	const [preview, setPreview] = useState(null);
	const [loadingAction, setLoadingAction] = useState(null);
	const [error, setError] = useState(null);

	const loadPreview = async (action) => {
		setLoadingAction(action);
		setError(null);
		try {
			const response = await axios.get(actionUrl(row.actions.preview_url_template, action));
			setPreview(response.data);
		} catch (exception) {
			setError(exception.response?.data?.message || "No se pudo preparar el preview.");
		} finally {
			setLoadingAction(null);
		}
	};

	return (
		<section className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
			<Subheading level={3}>Acciones correctivas</Subheading>
			{row.actions.enabled ? null : (
				<Text className="mt-2 text-sm text-amber-600">
					Acciones deshabilitadas por configuración del ambiente.
				</Text>
			)}
			{error ? <Text className="mt-2 text-sm text-red-600">{error}</Text> : null}
			<div className="mt-3 grid gap-2">
				{correctiveActions.map(([action, label]) => {
					const permitted = Boolean(canActions[action]);
					const hiddenBySource = (action === "activate-murguia-membership" && row.source.action !== "ALTA")
						|| (action === "deactivate-murguia-membership" && row.source.action !== "BAJA");
					if (hiddenBySource) return null;
					return (
						<div key={action} className="flex items-center justify-between gap-3 rounded-lg border border-zinc-100 px-3 py-2 text-sm dark:border-zinc-800">
							<div>
								<div className="font-medium">{label}</div>
								{permitted ? null : <div className="text-xs text-zinc-500">No disponible: sin permiso</div>}
							</div>
							<Button
								type="button"
								outline
								disabled={!permitted || loadingAction === action}
								onClick={() => loadPreview(action)}
							>
								{loadingAction === action ? "Preparando" : previewButtonLabel(action)}
							</Button>
						</div>
					);
				})}
			</div>
			<ListPanel
				title="Historial de acciones"
				items={row.actions.items.map((action) => actionSummary(action))}
				empty="Sin acciones correctivas"
			/>
			<ActionPreviewDialog
				row={row}
				preview={preview}
				onClose={() => setPreview(null)}
			/>
		</section>
	);
}

function ActionPreviewDialog({ row, preview, onClose }) {
	const form = useForm({ reason: "", confirmation: "", preview_token: preview?.token || "" });

	useEffect(() => {
		form.setData("preview_token", preview?.token || "");
	}, [preview?.token]);

	if (!preview) return null;

	const submit = (event) => {
		event.preventDefault();
		form.post(actionUrl(row.actions.execute_url_template, preview.action), {
			preserveScroll: true,
			onSuccess: onClose,
		});
	};

	return (
		<Dialog
			open={Boolean(preview)}
			onClose={onClose}
			size="6xl"
			className="flex max-h-[92vh] flex-col overflow-hidden [--gutter:theme(spacing.0)] sm:max-h-[88vh]"
		>
			<div className="shrink-0 border-b border-zinc-200 bg-white px-4 py-4 sm:px-6 dark:border-zinc-800 dark:bg-slate-900">
				<DialogTitle>{actionLabel(preview.action)}</DialogTitle>
			</div>
			<DialogBody className="!mt-0 min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-6">
				{preview.allowed ? (
					<Notice>Preview listo. Revisa el cambio antes de confirmar.</Notice>
				) : (
					<Notice color="red">
						No disponible: {preview.blocked_reason || "La acción está bloqueada."}
					</Notice>
				)}
				<div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
					<DetailSection title="Antes" items={objectItems(preview.before)} wrapSafe />
					<DetailSection title="Después" items={objectItems(preview.after)} wrapSafe />
					<DetailSection title="Objetivo" items={objectItems(preview.target)} wrapSafe />
					<DetailSection title="Evidencia" items={objectItems(preview.evidence)} wrapSafe />
					{preview.warnings?.length ? (
						<ListPanel
							title="Advertencias"
							items={preview.warnings}
							className="lg:col-span-2"
							wrapSafe
						/>
					) : null}
				</div>
				{preview.allowed ? (
					<form onSubmit={submit} className="mt-4 space-y-3">
						<Textarea
							value={form.data.reason}
							onChange={(event) => form.setData("reason", event.target.value)}
							placeholder="Motivo obligatorio"
						/>
						<Input
							value={form.data.confirmation}
							onChange={(event) => form.setData("confirmation", event.target.value)}
							placeholder="Escriba CONFIRMAR"
						/>
						<input type="hidden" value={preview.token} readOnly />
						<Button type="submit" disabled={form.processing || !row.actions.enabled}>
							Ejecutar acción
						</Button>
					</form>
				) : null}
			</DialogBody>
			<DialogActions className="!mt-0 shrink-0 border-t border-zinc-200 bg-white px-4 py-4 sm:px-6 dark:border-zinc-800 dark:bg-slate-900">
				<Button type="button" outline onClick={onClose}>
					Cerrar
				</Button>
			</DialogActions>
		</Dialog>
	);
}

function actionUrl(template, action) {
	return template.replace("__ACTION__", action);
}

function actionLabel(action) {
	return Object.fromEntries(correctiveActions)[action] || action;
}

function previewButtonLabel(action) {
	return {
		"activate-murguia-membership": "Previsualizar alta Murguía",
		"deactivate-murguia-membership": "Previsualizar baja Murguía",
		"retry-murguia-sync": "Previsualizar reintento",
		"update-email": "Previsualizar email",
	}[action] || "Preview";
}

function objectItems(value) {
	if (!value || typeof value !== "object") return [];
	return Object.entries(value).map(([key, val]) => [
		key,
		Array.isArray(val) ? val.join("; ") : (val && typeof val === "object" ? JSON.stringify(val) : val),
	]);
}

function actionSummary(action) {
	const before = action.before?.email || action.before?.customerable_id || "—";
	const after = action.after?.email || action.after?.customerable_id || "—";
	const status = action.status === "COMPLETED" ? "Completado" : action.status;
	return `${action.performed_at || action.created_at || "—"} — ${action.performed_by || "—"} — ${action.action_type}: ${before} → ${after} (${status})`;
}

function ReviewPanel({ row, canReview }) {
	const form = useForm({ review_status: row.review.status, comment: "" });
	const submit = (event) => {
		event.preventDefault();
		if (["CONFIRMED", "REJECTED"].includes(form.data.review_status) && !confirm("¿Confirmar esta decisión de auditoría?")) {
			return;
		}
		form.patch(row.review.update_url, { preserveScroll: true });
	};
	return (
		<section className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
			<Subheading level={3}>Revisión</Subheading>
			<div className="mt-3 space-y-2 text-sm">
				<div>Estado: <Badge color={reviewColor(row.review.status)}>{reviewLabel(row.review.status)}</Badge></div>
				<div>Responsable: {row.review.reviewed_by || "—"}</div>
				<div>Fecha: {row.review.reviewed_at || "—"}</div>
			</div>
			{canReview ? (
				<form onSubmit={submit} className="mt-4 space-y-3">
					<Select value={form.data.review_status} onChange={(e) => form.setData("review_status", e.target.value)}>
						{["PENDING", "REVIEWED", "CONFIRMED", "REJECTED", "FOLLOW_UP", "NOT_APPLICABLE"].map((status) => (
							<option key={status} value={status}>{reviewLabel(status)}</option>
						))}
					</Select>
					<Textarea
						value={form.data.comment}
						onChange={(e) => form.setData("comment", e.target.value)}
						placeholder="Comentario opcional"
					/>
					<Button type="submit" disabled={form.processing}>Guardar revisión</Button>
				</form>
			) : null}
			<ListPanel title="Comentarios" items={row.review.notes.map((note) => `${note.created_at} — ${note.user || "—"}: ${note.note}`)} />
			<ListPanel title="Historial" items={row.review.audits.map((audit) => `${audit.created_at} — ${audit.user || "—"} — ${audit.action}: ${audit.from_value || "—"} → ${audit.to_value || "—"}`)} />
		</section>
	);
}

function Summary({ summary }) {
	const identityCards = [
		["Total", summary.unique_collaborators],
		["Confirmados", summary.confirmed, "green"],
		["Revisión manual", summary.manual_review, "amber"],
		["No encontrados", summary.not_found, "red"],
		["Correo diferente", summary.email_different, "orange"],
		["Posibles duplicados", summary.possible_duplicates, "violet"],
	];
	const operationCards = [
		["ALTAS solicitadas", summary.altas, "green"],
		["BAJAS solicitadas", summary.bajas, "amber"],
		["Sin acción", summary.sin_accion],
		["Pendientes", summary.acciones_pendientes, "amber"],
		["Procesadas", summary.acciones_procesadas, "green"],
		["Bloqueadas", summary.acciones_bloqueadas, "orange"],
		["Con error", summary.acciones_error, "red"],
	];
	return (
		<div className="space-y-4">
			<KpiGroup title="Identidad / conciliación" cards={identityCards} />
			<KpiGroup title="Operación ODESSA/Murguía" cards={operationCards} />
		</div>
	);
}

function KpiGroup({ title, cards }) {
	return (
		<section>
			<Subheading level={2}>{title}</Subheading>
			<div className="mt-2 grid gap-3 sm:grid-cols-2 lg:grid-cols-6">
				{cards.map(([label, value, color]) => (
					<div key={label} className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">{label}</Text>
						<p className={`mt-1 text-2xl font-semibold tabular-nums ${summaryColorClass(color)}`}>{Number(value || 0).toLocaleString("es-MX")}</p>
					</div>
				))}
			</div>
		</section>
	);
}

function OperationViews({ views }) {
	if (!views) return null;
	return (
		<div className="grid gap-4 lg:grid-cols-2">
			<OperationView title="ALTAS ODESSA" totalLabel="solicitadas" view={views.altas} />
			<OperationView title="BAJAS ODESSA" totalLabel="solicitadas" view={views.bajas} />
		</div>
	);
}

function OperationView({ title, totalLabel, view }) {
	const metrics = [
		["Listas para procesar", view?.ready],
		["Ya activas/inactivas", view?.already_ok],
		["Bloqueadas", view?.blocked],
		["Con error", view?.error],
		["Sin noCredito", view?.without_credit_number],
		["Correo diferente", view?.email_different],
	];
	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex items-baseline justify-between gap-4">
				<Subheading level={2}>{title}</Subheading>
				<div className="text-sm font-medium text-zinc-500">{Number(view?.total || 0).toLocaleString("es-MX")} {totalLabel}</div>
			</div>
			<div className="mt-3 grid grid-cols-2 gap-2 text-sm">
				{metrics.map(([label, value]) => (
					<div key={label} className="flex justify-between gap-2 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800">
						<span className="text-zinc-500">{label}</span>
						<span className="font-semibold tabular-nums">{Number(value || 0).toLocaleString("es-MX")}</span>
					</div>
				))}
			</div>
		</section>
	);
}

function DetailSection({ title, items, links = [], wrapSafe = false }) {
	return (
		<section className={`${wrapSafe ? "min-w-0 overflow-visible" : ""} rounded-lg border border-zinc-200 p-4 dark:border-zinc-700`}>
			<Subheading level={3}>{title}</Subheading>
			<dl className={wrapSafe ? "mt-3 grid grid-cols-1 gap-x-4 gap-y-2 text-sm min-[520px]:grid-cols-[140px_minmax(0,1fr)]" : "mt-3 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2 text-sm"}>
				{items.map(([label, value]) => wrapSafe ? (
					<div
						key={label}
						className={longPreviewField(label, value) ? "min-w-0 min-[520px]:col-span-2" : "contents"}
					>
						<dt className="min-w-0 whitespace-normal text-zinc-500">{label}</dt>
						<dd className={`min-w-0 break-words ${valueWrapClass(label)}`}>{value || "—"}</dd>
					</div>
				) : (
					<div key={label} className="contents">
						<dt className="text-zinc-500">{label}</dt>
						<dd className="break-words">{value || "—"}</dd>
					</div>
				))}
			</dl>
			<div className="mt-4 flex flex-wrap gap-2">
				{links.filter(Boolean).map(([label, href]) => <Button key={href} href={href} outline>{label}</Button>)}
			</div>
		</section>
	);
}

function ListPanel({ title, items, empty = "Sin datos", className = "", wrapSafe = false }) {
	return (
		<section className={`${wrapSafe ? "min-w-0 overflow-visible" : ""} rounded-lg border border-zinc-200 p-4 dark:border-zinc-700 ${className}`}>
			<Subheading level={3}>{title}</Subheading>
			{items.length ? (
				<ul className="mt-3 space-y-2 text-sm">
					{items.map((item, i) => (
						<li key={`${item}-${i}`} className={wrapSafe ? "min-w-0 whitespace-normal break-words [overflow-wrap:anywhere]" : ""}>
							{item}
						</li>
					))}
				</ul>
			) : <Text className="mt-3 text-sm text-zinc-500">{empty}</Text>}
		</section>
	);
}

function longPreviewField(label, value) {
	const normalized = String(label || "").toLowerCase();
	return normalized.includes("evidence")
		|| normalized.includes("evidencia")
		|| String(value || "").length > 95;
}

function valueWrapClass(label) {
	const normalized = String(label || "").toLowerCase();
	return normalized.includes("email")
		|| normalized.includes("correo")
		|| normalized.includes("identifier")
		|| normalized.includes("identificador")
		|| normalized.includes("nocredito")
		|| normalized.includes("id")
		? "break-all"
		: "[overflow-wrap:anywhere]";
}

function FlagList({ flags, labels }) {
	if (!flags.length) return <span className="text-xs text-zinc-400">—</span>;
	const display = labels?.length ? labels : flags;
	return <div className="flex flex-wrap gap-1">{display.slice(0, 2).map((flag, index) => <Badge key={`${flag}-${index}`} color={String(flag).includes("Correo") || String(flag).includes("EMAIL") ? "orange" : "violet"}>{flag}</Badge>)}</div>;
}

function SourceActionBadge({ action }) {
	return <Badge color={sourceActionColor(action)}>{sourceActionLabel(action)}</Badge>;
}

function ExistenceBadge({ exists }) {
	return <Badge color={exists ? "green" : "zinc"}>{exists ? "Sí" : "No"}</Badge>;
}

function SystemBadge({ label, exists }) {
	return <Badge color={exists ? "green" : "zinc"}>{label} {exists ? "✓" : "✕"}</Badge>;
}

function CreditNumber({ value }) {
	if (!value) return <Badge color="amber">SIN noCredito</Badge>;
	return <span className="font-mono text-sm">{value}</span>;
}

function Notice({ color, children }) {
	return <div className={`rounded-lg border px-4 py-3 text-sm ${color === "red" ? "border-red-200 bg-red-50 text-red-900" : "border-emerald-200 bg-emerald-50 text-emerald-900"}`}>{children}</div>;
}

function matchesQuickFilter(row, key) {
	if (key === "altas") return row.source.action === "ALTA";
	if (key === "bajas") return row.source.action === "BAJA";
	if (key === "sin_accion") return row.source.action === "NONE";
	if (key === "exact_matches") return exactMatchTypes.includes(row.match.type);
	if (key === "possible_matches") return ["MATCH_PROBABLE_IDENTITY", "MATCH_AMBIGUOUS", "MATCH_DELETED_RECORD"].includes(row.match.type) || Number(row.match.candidate_count || 0) > 1;
	if (key === "data_errors") return row.dimensions.source_action_status === "FAILED" || row.murguia.audit_status === "MURGUIA_SYNC_ERROR" || (row.dimensions.flags || []).length > 0 || !row.membership.identifier;
	if (key === "history") return row.actions?.items?.length > 0 || row.review?.audits?.length > 0 || row.review?.notes?.length > 0;
	if (key === "pending") return ["PENDING_ACTIVATION", "PENDING_DEACTIVATION"].includes(row.dimensions.source_action_status);
	if (key === "blocked") return row.dimensions.source_action_status === "BLOCKED";
	if (key === "errors") return row.dimensions.source_action_status === "FAILED" || row.murguia.audit_status === "MURGUIA_SYNC_ERROR";
	if (key === "not_found") return row.dimensions.final_status === "NO_REGISTRADO_EN_FAMEDIC";
	if (key === "email_different") return row.comparisons.email_status === "email_different";
	if (key === "not_found_murguia") return row.murguia.status === "FAMEDIC_NO_MURGUIA";
	if (key === "without_number") return !row.membership.identifier;
	if (key === "processed") return ["ACTIVATED", "DEACTIVATED", "ALREADY_ACTIVE", "ALREADY_INACTIVE"].includes(row.dimensions.source_action_status);
	if (key === "possible_duplicates") return row.dimensions.flags?.some((flag) => ["POSSIBLE_DUPLICATE_PERSON", "POSSIBLE_EXISTING_USER", "DUPLICATE_ODESSA_ID", "DUPLICATE_COMPANY_PARTNER", "DUPLICATE_MEMBERSHIP_IDENTIFIER"].includes(flag));
	return true;
}

function matchesAdvancedFilters(row, filters) {
	if (filters.sourceAction && row.source.action !== filters.sourceAction) return false;
	if (filters.actionStatus && row.dimensions.source_action_status !== filters.actionStatus) return false;
	if (filters.murguiaStatus && row.murguia.status !== filters.murguiaStatus) return false;
	if (filters.matchType && row.match.type !== filters.matchType) return false;
	if (filters.flag && !(row.dimensions.flags || []).includes(filters.flag)) return false;
	if (filters.credit === "with" && !row.membership.identifier) return false;
	if (filters.credit === "without" && row.membership.identifier) return false;
	if (filters.emailDifferent === "yes" && row.comparisons.email_status !== "email_different") return false;
	if (filters.emailDifferent === "no" && row.comparisons.email_status === "email_different") return false;
	if (filters.duplicate === "yes" && !hasDuplicateFlag(row)) return false;
	if (filters.duplicate === "no" && hasDuplicateFlag(row)) return false;
	if (filters.actionState === "available" && !["PENDING_ACTIVATION", "PENDING_DEACTIVATION"].includes(row.dimensions.source_action_status)) return false;
	if (filters.actionState === "blocked" && row.dimensions.source_action_status !== "BLOCKED") return false;
	if (filters.actionState === "executed" && !["ACTIVATED", "DEACTIVATED", "ALREADY_ACTIVE", "ALREADY_INACTIVE", "COMPLETED"].includes(row.dimensions.source_action_status)) return false;
	if (filters.actionState === "failed" && row.dimensions.source_action_status !== "FAILED") return false;
	if (filters.reviewStatus && row.review?.status !== filters.reviewStatus) return false;
	return true;
}

function sortRows(rows, sort) {
	const direction = sort.direction === "desc" ? -1 : 1;
	return [...rows].sort((left, right) => {
		const a = sortValue(left, sort.key);
		const b = sortValue(right, sort.key);
		return String(a ?? "").localeCompare(String(b ?? ""), "es", { numeric: true, sensitivity: "base" }) * direction;
	});
}

function sortValue(row, key) {
	if (key === "priority") return `${row.dimensions.source_action_status || ""}-${row.review?.status || ""}`;
	if (key === "evidence") return (row.match.evidence || []).join(" ");
	if (key === "alerts") return (row.dimensions.flag_labels || row.dimensions.flags || []).join(" ");
	if (key === "compare") return `${row.source.email || ""} ${row.famedic.email || ""} ${row.murguia.status || ""}`;
	if (key === "critical") return criticalDiffs(row).join(" ");
	if (key === "history") return lastActionLabel(row);
	if (key === "lastAction") return lastActionLabel(row);
	return key.split(".").reduce((value, part) => value?.[part], row);
}

function uniqueOption(value, index, values) {
	return value && values.indexOf(value) === index;
}

function hasDuplicateFlag(row) {
	return (row.dimensions.flags || []).some((flag) => duplicateFlags.includes(flag));
}

function criticalDiffs(row) {
	const diffs = [];
	if (row.comparisons.email_status === "email_different" || (row.dimensions.flags || []).includes("EMAIL_DIFFERENT")) diffs.push("Email distinto");
	if (row.comparisons.odessa_id_matches === "No") diffs.push("ID ODESSA distinto");
	if (row.comparisons.partner_matches === "No") diffs.push("# empleado distinto");
	if (!row.membership.identifier) diffs.push("noCredito faltante");
	if (Number(row.match.candidate_count || 0) > 1) diffs.push("Múltiples customers");
	if (hasDuplicateFlag(row)) diffs.push("Duplicado probable");
	return [...new Set(diffs)];
}

function lastActionLabel(row) {
	const action = row.actions?.items?.[0];
	if (!action) return "Sin acciones";
	return `${action.action_type || "Acción"} · ${action.status || "—"} · ${action.performed_at || action.created_at || "—"}`;
}

function matchTypeLabel(value) {
	return {
		MATCH_CONFIRMED_ODESSA_ID: "Exacta: ID ODESSA",
		MATCH_CONFIRMED_COMPANY_PARTNER: "Exacta: empresa + empleado",
		MATCH_CONFIRMED_MEMBERSHIP: "Exacta: noCredito",
		MATCH_CONFIRMED_EMAIL: "Exacta: email",
		MATCH_PROBABLE_IDENTITY: "Posible: identidad",
		MATCH_AMBIGUOUS: "Ambigua",
		MATCH_DELETED_RECORD: "Registro eliminado",
		NO_MATCH: "Sin match",
	}[value] || value || "Sin match";
}

function flagLabel(value) {
	return {
		EMAIL_DIFFERENT: "Correo diferente en FAMEDIC",
		POSSIBLE_DUPLICATE_PERSON: "Posible persona duplicada",
		POSSIBLE_EXISTING_USER: "Posible usuario existente",
		DUPLICATE_ODESSA_ID: "ID ODESSA duplicado",
		DUPLICATE_COMPANY_PARTNER: "Empresa + empleado duplicado",
		DUPLICATE_MEMBERSHIP_IDENTIFIER: "noCredito duplicado",
		DISCREPANCIA_IDENTITY: "Discrepancia de identidad",
		SUBSCRIPTION_WITHOUT_IDENTIFIER: "Suscripción sin noCredito",
		IDENTIFIER_WITHOUT_SUBSCRIPTION: "noCredito sin suscripción activa",
		UNKNOWN_SOURCE_ACTION_COLOR: "Acción ODESSA desconocida",
	}[value] || value;
}

function reviewLabel(value) {
	return { PENDING: "Pendiente", REVIEWED: "Revisado", CONFIRMED: "Confirmado", REJECTED: "Rechazado", FOLLOW_UP: "Seguimiento", NOT_APPLICABLE: "No aplica" }[value] || value;
}
function reviewColor(value) {
	return { PENDING: "amber", REVIEWED: "sky", CONFIRMED: "green", REJECTED: "red", FOLLOW_UP: "orange", NOT_APPLICABLE: "zinc" }[value] || "zinc";
}
function matchColor(value) {
	if (value === "MATCH_CONFIRMED_ODESSA_ID") return "green";
	if (value === "MATCH_PROBABLE_IDENTITY") return "amber";
	if (value === "NO_MATCH") return "red";
	return "sky";
}
function membershipColor(value) {
	if (value === "ACTIVE") return "green";
	if (value === "EXPIRED") return "red";
	if (value === "MISSING") return "amber";
	return "zinc";
}
function murguiaColor(value) {
	if (value === "FAMEDIC_Y_MURGUIA") return "green";
	if (value === "FAMEDIC_NO_MURGUIA") return "amber";
	if (value === "MURGUIA_NO_FAMEDIC") return "red";
	return "zinc";
}
function murguiaLabel(value) {
	return { FAMEDIC_Y_MURGUIA: "En Murguía", FAMEDIC_NO_MURGUIA: "No en Murguía", MURGUIA_NO_FAMEDIC: "Solo Murguía" }[value] || "Sin Murguía";
}

function sourceActionLabel(value) {
	return { ALTA: "ALTA", BAJA: "BAJA", NONE: "SIN ACCIÓN", UNKNOWN: "DESCONOCIDA" }[value] || value || "SIN ACCIÓN";
}
function sourceActionColor(value) {
	return { ALTA: "green", BAJA: "amber", NONE: "zinc", UNKNOWN: "red" }[value] || "zinc";
}
function actionStatusLabel(value) {
	return {
		NO_ACTION: "Sin acción",
		PENDING_ACTIVATION: "Pendiente de alta",
		PENDING_DEACTIVATION: "Pendiente de baja",
		ALREADY_ACTIVE: "Ya activo",
		ALREADY_INACTIVE: "Ya inactivo",
		COMPLETED: "Completado",
		ACTIVATED: "Activado",
		DEACTIVATED: "Desactivado",
		BLOCKED: "Bloqueado",
		FAILED: "Error",
	}[value] || value || "Sin acción";
}
function actionStatusColor(value) {
	if (["ACTIVATED", "DEACTIVATED", "ALREADY_ACTIVE", "ALREADY_INACTIVE", "COMPLETED"].includes(value)) return "green";
	if (["PENDING_ACTIVATION", "PENDING_DEACTIVATION"].includes(value)) return "amber";
	if (value === "FAILED") return "red";
	if (value === "BLOCKED") return "orange";
	return "zinc";
}
function suggestedAction(row) {
	if (row.source.action === "ALTA") return "Alta Murguía individual";
	if (row.source.action === "BAJA") return "Baja Murguía individual";
	return "No aplica";
}
function summaryColorClass(color) {
	return { red: "text-red-600", amber: "text-amber-600", orange: "text-orange-600", green: "text-emerald-600", violet: "text-violet-600" }[color] || "";
}
