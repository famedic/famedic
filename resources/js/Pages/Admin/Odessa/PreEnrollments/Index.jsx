import { router } from "@inertiajs/react";
import { ArrowDownTrayIcon, DocumentMagnifyingGlassIcon, EyeIcon } from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Input } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";

export default function Index({ preEnrollments, dashboard, filters = {}, filterOptions, canManage, successMessage }) {
	const apply = (changes) => {
		router.get(route("admin.odessa.pre-enrollments.index"), { ...filters, ...changes }, {
			preserveState: true,
			preserveScroll: true,
		});
	};

	return (
		<AdminLayout title="ODESSA — preafiliaciones">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<div className="flex flex-wrap items-start justify-between gap-4">
					<div>
						<Heading>ODESSA / Preafiliaciones</Heading>
						<Text className="mt-1 text-sm text-zinc-500">
							Colaboradores institucionales preparados antes de crear cuenta FAMEDIC.
						</Text>
					</div>
					<div className="flex flex-wrap gap-2">
						{canManage ? (
							<Button href={route("admin.odessa.pre-enrollments.import")}>
								<DocumentMagnifyingGlassIcon data-slot="icon" />
								Preview import
							</Button>
						) : null}
						<a
							href={route("admin.odessa.pre-enrollments.export", filters)}
							className="inline-flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-2 text-sm font-semibold hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
						>
							<ArrowDownTrayIcon className="size-4" />
							Export
						</a>
					</div>
				</div>

				{successMessage ? <Notice>{successMessage}</Notice> : null}

				<Kpis dashboard={dashboard} />

				<div className="grid gap-3 lg:grid-cols-[1.5fr_repeat(5,minmax(9rem,1fr))]">
						<Input
							defaultValue={filters.search || ""}
							placeholder="Buscar por datos administrativos autorizados"
							onBlur={(event) => apply({ search: event.target.value })}
							onKeyDown={(event) => event.key === "Enter" && apply({ search: event.currentTarget.value })}
						/>
					<FilterSelect value={filters.source_action} label="Acción" values={filterOptions.source_actions} onChange={(value) => apply({ source_action: value })} />
					<FilterSelect value={filters.status} label="Estado" values={filterOptions.statuses} onChange={(value) => apply({ status: value })} />
					<FilterSelect value={filters.link_status} label="Vínculo" values={filterOptions.link_statuses} onChange={(value) => apply({ link_status: value })} />
					<FilterSelect value={filters.murguia_status} label="Murguía" values={filterOptions.murguia_statuses} onChange={(value) => apply({ murguia_status: value })} />
					<Select value={filters.credit || ""} onChange={(event) => apply({ credit: event.target.value })}>
						<option value="">noCredito</option>
						<option value="with">Con noCredito</option>
						<option value="without">Sin noCredito</option>
					</Select>
				</div>

				<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Acción</TableHeader>
								{canManage ? <TableHeader>Colaborador</TableHeader> : null}
								<TableHeader>Fila</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader>noCredito</TableHeader>
								<TableHeader>Murguía</TableHeader>
								<TableHeader>Cuenta FAMEDIC</TableHeader>
								<TableHeader>Estado vínculo</TableHeader>
								<TableHeader>Alertas</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{preEnrollments.data.length === 0 ? (
								<TableRow>
									<TableCell colSpan={canManage ? 10 : 9} className="py-10 text-center text-zinc-500">
										Todavía no hay preafiliaciones.
									</TableCell>
								</TableRow>
							) : preEnrollments.data.map((item) => (
								<TableRow key={item.id}>
									<TableCell><SourceAction action={item.source_action} /></TableCell>
									{canManage ? <TableCell><Collaborator identity={item.identity} /></TableCell> : null}
									<TableCell>{item.source_row || "—"}</TableCell>
									<TableCell><Badge color={statusColor(item.status)}>{item.status}</Badge></TableCell>
									<TableCell><MembershipIdentifier item={item} canManage={canManage} /></TableCell>
									<TableCell><Badge color={murguiaColor(item.murguia_status)}>{item.murguia_status}</Badge></TableCell>
									<TableCell>{item.has_linked_user || item.has_linked_customer || item.has_linked_odessa_account ? "Detectada" : "—"}</TableCell>
									<TableCell><Badge color={linkColor(item.link_status)}>{item.link_status}</Badge></TableCell>
									<TableCell><Flags flags={item.data_quality_flags} /></TableCell>
									<TableCell className="text-right">
										<Button href={item.show_url} outline>
											<EyeIcon data-slot="icon" />
											Ver
										</Button>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>
			</div>
		</AdminLayout>
	);
}

function Kpis({ dashboard }) {
	const cards = [
		["Total precargados", dashboard.total],
		["Altas", dashboard.altas, "green"],
		["Históricos", dashboard.historicos],
		["Pendientes cuenta", dashboard.pending_account, "amber"],
		["Listos", dashboard.ready, "green"],
		["Vinculados", dashboard.linked, "green"],
		["Bloqueados", dashboard.blocked, "red"],
		["Con noCredito", dashboard.with_credit],
		["Sin noCredito", dashboard.without_credit, "amber"],
		["Murguía activo", dashboard.murguia_active, "green"],
		["Murguía pendiente", dashboard.murguia_pending, "amber"],
		["Murguía error", dashboard.murguia_error, "red"],
		["Posibles duplicados", dashboard.possible_duplicates, "violet"],
	];
	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5 xl:grid-cols-7">
			{cards.map(([label, value, color]) => (
				<div key={label} className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
					<Text className="text-xs text-zinc-500">{label}</Text>
					<p className={`mt-1 text-2xl font-semibold tabular-nums ${colorClass(color)}`}>{Number(value || 0).toLocaleString("es-MX")}</p>
				</div>
			))}
		</div>
	);
}

function FilterSelect({ label, value, values, onChange }) {
	return (
		<Select value={value || ""} onChange={(event) => onChange(event.target.value)}>
			<option value="">{label}</option>
			{values.map((option) => <option key={option} value={option}>{option}</option>)}
		</Select>
	);
}

function SourceAction({ action }) {
	return <Badge color={action === "ALTA" ? "green" : action === "BAJA" ? "amber" : "zinc"}>{action}</Badge>;
}

function MembershipIdentifier({ item, canManage }) {
	if (!item.has_medical_attention_identifier) {
		return <Badge color="amber">Pendiente</Badge>;
	}

	if (canManage && item.medical_attention_identifier) {
		return <span className="font-mono text-sm tabular-nums">{item.medical_attention_identifier}</span>;
	}

	return <Badge color="green">Reservado</Badge>;
}

function Collaborator({ identity }) {
	const name = identity?.full_name || "—";
	const company = identity?.company || "—";
	const employee = identity?.employee_identifier_masked || "—";
	const email = identity?.source_email_masked || "—";

	return (
		<div className="min-w-56 max-w-80">
			<div className="font-medium text-zinc-900 dark:text-zinc-100">{name}</div>
			<div className="mt-0.5 text-xs text-zinc-500">{company} · Empleado {employee}</div>
			<div className="mt-0.5 text-xs text-zinc-500">{email}</div>
		</div>
	);
}

function Flags({ flags = [] }) {
	if (!flags.length) return <span className="text-zinc-400">—</span>;
	return <div className="flex flex-wrap gap-1">{flags.slice(0, 2).map((flag) => <Badge key={flag} color="violet">{flag}</Badge>)}</div>;
}

function Notice({ children }) {
	return <div className="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{children}</div>;
}

function murguiaColor(status) {
	return status === "ACTIVE" ? "green" : status === "FAILED" ? "red" : status === "PENDING" ? "amber" : "zinc";
}
function statusColor(status) {
	return status === "READY" ? "green" : status === "BLOCKED" ? "red" : status === "PENDING" ? "amber" : "zinc";
}
function linkColor(status) {
	return status === "LINKED" ? "green" : status?.includes("CONFLICT") || status?.includes("DUPLICATE") ? "red" : status === "CANDIDATE_FOUND" ? "amber" : "zinc";
}
function colorClass(color) {
	return { green: "text-emerald-600", amber: "text-amber-600", red: "text-red-600", violet: "text-violet-600" }[color] || "";
}
