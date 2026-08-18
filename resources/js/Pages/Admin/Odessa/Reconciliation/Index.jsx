import { router, useForm } from "@inertiajs/react";
import { useMemo, useState } from "react";
import {
	ArrowDownTrayIcon,
	DocumentMagnifyingGlassIcon,
	EyeIcon,
	FunnelIcon,
	MagnifyingGlassIcon,
	TrashIcon,
} from "@heroicons/react/16/solid";

import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogActions,
	DialogBody,
	DialogTitle,
} from "@/Components/Catalyst/dialog";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import { Select } from "@/Components/Catalyst/select";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Text } from "@/Components/Catalyst/text";

const quickFilters = [
	{ key: "all", label: "Todos" },
	{ key: "complete", label: "Registro completo" },
	{ key: "email_different", label: "Correo diferente" },
	{ key: "without_membership", label: "Sin membresía" },
	{ key: "expired_membership", label: "Membresía vencida" },
	{ key: "not_found", label: "No registrados" },
	{ key: "manual_review", label: "Revisión manual" },
	{ key: "famedic_no_murguia", label: "FAMEDIC no Murguía" },
	{ key: "murguia_no_famedic", label: "Murguía no FAMEDIC" },
];

export default function Index({ preview, successMessage, errors = {} }) {
	const [selectedRow, setSelectedRow] = useState(null);
	const [quickFilter, setQuickFilter] = useState("all");
	const [search, setSearch] = useState("");
	const [advanced, setAdvanced] = useState({
		matchType: "",
		membershipStatus: "",
		murguiaStatus: "",
		accountStatus: "",
		flag: "",
		company: "",
	});

	const rows = preview?.rows || [];
	const filteredRows = useMemo(
		() =>
			rows.filter((row) => {
				if (!matchesQuickFilter(row, quickFilter)) return false;
				if (search && !row.search_text?.includes(search.toLowerCase())) return false;
				if (advanced.matchType && row.match.type !== advanced.matchType) return false;
				if (
					advanced.membershipStatus &&
					row.membership.status !== advanced.membershipStatus
				) {
					return false;
				}
				if (advanced.murguiaStatus && row.murguia.status !== advanced.murguiaStatus) {
					return false;
				}
				if (
					advanced.accountStatus &&
					row.dimensions.account_status !== advanced.accountStatus
				) {
					return false;
				}
				if (
					advanced.flag &&
					!row.dimensions.flags.includes(advanced.flag)
				) {
					return false;
				}
				if (advanced.company && row.source.company !== advanced.company) return false;

				return true;
			}),
		[advanced, quickFilter, rows, search],
	);

	return (
		<AdminLayout title="ODESSA — conciliación">
			<div className="space-y-6 text-zinc-900 dark:text-zinc-100">
				<header className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
					<div>
						<Heading>Conciliación ODESSA</Heading>
						<Text className="mt-1 max-w-3xl text-sm text-zinc-500 dark:text-zinc-400">
							Compara un padrón de colaboradores ODESSA contra FAMEDIC y,
							opcionalmente, contra un reporte Murguía.
						</Text>
					</div>
					{preview ? (
						<div className="flex flex-wrap gap-2">
							<a
								href={preview.export.url}
								className="relative inline-flex items-center justify-center gap-x-2 rounded-lg border border-transparent bg-famedic-dark px-3 py-2 text-sm font-semibold text-white shadow-sm hover:bg-famedic-dark/90 dark:bg-famedic-lime dark:text-famedic-darker"
							>
								<ArrowDownTrayIcon data-slot="icon" />
								Exportar XLSX
							</a>
							<Button type="button" outline onClick={clearPreview}>
								<TrashIcon data-slot="icon" />
								Limpiar
							</Button>
						</div>
					) : null}
				</header>

				{successMessage ? <Notice color="emerald">{successMessage}</Notice> : null}
				{errors.export ? <Notice color="red">{errors.export}</Notice> : null}

				<UploadPanel errors={errors} />

				{preview ? (
					<>
						<RunMeta meta={preview.meta} />
						<Summary summary={preview.summary} />
						<Filters
							quickFilter={quickFilter}
							setQuickFilter={setQuickFilter}
							search={search}
							setSearch={setSearch}
							advanced={advanced}
							setAdvanced={setAdvanced}
							options={preview.filters}
						/>
						<ResultTable rows={filteredRows} onView={setSelectedRow} />
					</>
				) : (
					<EmptyState />
				)}

				<DetailDialog row={selectedRow} onClose={() => setSelectedRow(null)} />
			</div>
		</AdminLayout>
	);
}

function UploadPanel({ errors }) {
	const form = useForm({
		source_file: null,
		murguia_file: null,
	});

	const submit = (event) => {
		event.preventDefault();
		form.post(route("admin.odessa.reconciliation.analyze"), {
			forceFormData: true,
			preserveScroll: true,
		});
	};

	return (
		<form
			onSubmit={submit}
			className="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-900"
		>
			<div className="grid gap-5 lg:grid-cols-[1fr_1fr_auto] lg:items-end">
				<FileField
					label="Reporte de colaboradores"
					required
					error={form.errors.source_file || errors.source_file}
					onChange={(file) => form.setData("source_file", file)}
				/>
				<FileField
					label="Reporte Murguía / padrón mensual"
					error={form.errors.murguia_file || errors.murguia_file}
					onChange={(file) => form.setData("murguia_file", file)}
				/>
				<Button type="submit" disabled={form.processing || !form.data.source_file}>
					<DocumentMagnifyingGlassIcon data-slot="icon" />
					{form.processing ? "Analizando…" : "Analizar conciliación"}
				</Button>
			</div>
		</form>
	);
}

function FileField({ label, required = false, error, onChange }) {
	return (
		<div>
			<Text className="mb-2 text-sm font-medium">
				{label} {required ? <span className="text-red-600">*</span> : null}
			</Text>
			<input
				type="file"
				accept=".xlsx,.xls"
				onChange={(event) => onChange(event.target.files?.[0] || null)}
				className="block w-full text-sm file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-100"
			/>
			{error ? <Text className="mt-1 text-sm text-red-600">{error}</Text> : null}
		</div>
	);
}

function RunMeta({ meta }) {
	return (
		<div className="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm dark:border-zinc-700 dark:bg-zinc-800/50">
			<p>
				<span className="font-medium">Reporte:</span> {meta.source_filename}
			</p>
			<p className="text-zinc-600 dark:text-zinc-400">
				Murguía: {meta.murguia_filename || "No cargado"} · Generado:{" "}
				{meta.generated_at}
			</p>
		</div>
	);
}

function Summary({ summary }) {
	const cards = [
		["Colaboradores", summary.unique_collaborators, "zinc"],
		["Confirmados", summary.confirmed, "emerald"],
		["Revisión manual", summary.manual_review, "amber"],
		["No registrados", summary.not_found, "red"],
		["Registro completo", summary.complete, "green"],
		["Correo diferente", summary.email_different, "orange"],
		["Sin membresía", summary.without_membership, "amber"],
		["Membresía vencida", summary.expired_membership, "red"],
		["FAMEDIC + Murguía", summary.famedic_and_murguia, "sky"],
		["FAMEDIC no Murguía", summary.famedic_no_murguia, "amber"],
		["Murguía no FAMEDIC", summary.murguia_no_famedic, "red"],
	];

	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
			{cards.map(([label, value, color]) => (
				<div
					key={label}
					className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
				>
					<Text className="text-xs text-zinc-500">{label}</Text>
					<p className={`mt-1 text-2xl font-semibold tabular-nums ${textColor(color)}`}>
						{Number(value || 0).toLocaleString("es-MX")}
					</p>
				</div>
			))}
		</div>
	);
}

function Filters({
	quickFilter,
	setQuickFilter,
	search,
	setSearch,
	advanced,
	setAdvanced,
	options,
}) {
	const setAdvancedValue = (key, value) =>
		setAdvanced((current) => ({ ...current, [key]: value }));

	return (
		<div className="space-y-4">
			<div className="flex flex-wrap gap-2">
				{quickFilters.map((filter) => (
					<Button
						key={filter.key}
						type="button"
						outline={quickFilter !== filter.key}
						onClick={() => setQuickFilter(filter.key)}
						className="text-xs"
					>
						{filter.label}
					</Button>
				))}
			</div>

			<div className="grid gap-3 lg:grid-cols-[minmax(16rem,1fr)_repeat(3,minmax(10rem,14rem))]">
				<InputGroup>
					<MagnifyingGlassIcon />
					<Input
						value={search}
						onChange={(event) => setSearch(event.target.value)}
						placeholder="Buscar nombre, email, ID ODESSA, socio, user, customer o membresía"
					/>
				</InputGroup>
				<Select
					value={advanced.matchType}
					onChange={(event) => setAdvancedValue("matchType", event.target.value)}
				>
					<option value="">Match type</option>
					{options.match_types.map((value) => (
						<option key={value} value={value}>
							{value}
						</option>
					))}
				</Select>
				<Select
					value={advanced.membershipStatus}
					onChange={(event) =>
						setAdvancedValue("membershipStatus", event.target.value)
					}
				>
					<option value="">Membresía</option>
					{options.membership_statuses.map((value) => (
						<option key={value} value={value}>
							{membershipLabel(value)}
						</option>
					))}
				</Select>
				<Select
					value={advanced.murguiaStatus}
					onChange={(event) => setAdvancedValue("murguiaStatus", event.target.value)}
				>
					<option value="">Murguía</option>
					{options.murguia_statuses.map((value) => (
						<option key={value} value={value}>
							{murguiaLabel(value)}
						</option>
					))}
				</Select>
			</div>
			<div className="grid gap-3 md:grid-cols-3">
				<Select
					value={advanced.accountStatus}
					onChange={(event) => setAdvancedValue("accountStatus", event.target.value)}
				>
					<option value="">Cuenta ODESSA</option>
					{options.account_statuses.map((value) => (
						<option key={value} value={value}>
							{accountLabel(value)}
						</option>
					))}
				</Select>
				<Select
					value={advanced.flag}
					onChange={(event) => setAdvancedValue("flag", event.target.value)}
				>
					<option value="">Flags</option>
					{options.flags.map((value) => (
						<option key={value} value={value}>
							{value}
						</option>
					))}
				</Select>
				<Select
					value={advanced.company}
					onChange={(event) => setAdvancedValue("company", event.target.value)}
				>
					<option value="">Empresa</option>
					{options.companies.map((value) => (
						<option key={value} value={value}>
							{value}
						</option>
					))}
				</Select>
			</div>
		</div>
	);
}

function ResultTable({ rows, onView }) {
	return (
		<div className="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
			<Table dense>
				<TableHead>
					<TableRow>
						<TableHeader>Colaborador</TableHeader>
						<TableHeader>Correos</TableHeader>
						<TableHeader>ID ODESSA</TableHeader>
						<TableHeader>Empresa / socio</TableHeader>
						<TableHeader>Match por</TableHeader>
						<TableHeader>Cuenta</TableHeader>
						<TableHeader>Membresía</TableHeader>
						<TableHeader>Murguía</TableHeader>
						<TableHeader>Alertas</TableHeader>
						<TableHeader />
					</TableRow>
				</TableHead>
				<TableBody>
					{rows.length === 0 ? (
						<TableRow>
							<TableCell colSpan={10} className="py-10 text-center text-zinc-500">
								Sin resultados para los filtros seleccionados.
							</TableCell>
						</TableRow>
					) : (
						rows.map((row) => (
							<TableRow key={row.id}>
								<TableCell className="min-w-64">
									<div className="font-medium">{row.source.name || "Sin nombre"}</div>
									<div className="text-xs text-zinc-500">
										Fila {row.source.row} · {row.source.birth_date || "sin fecha"}
									</div>
								</TableCell>
								<TableCell className="min-w-56 text-xs">
									<div>{row.source.email || "—"}</div>
									<div className="text-zinc-500">{row.famedic.email || "—"}</div>
								</TableCell>
								<TableCell className="font-mono text-xs">
									{row.source.odessa_id || "—"}
								</TableCell>
								<TableCell className="font-mono text-xs">
									{row.source.company || "—"} / {row.source.employee || "—"}
								</TableCell>
								<TableCell>
									<Badge color={matchColor(row.match.type)}>{row.match.label}</Badge>
								</TableCell>
								<TableCell>
									<Badge color={accountColor(row.dimensions.account_status)}>
										{accountLabel(row.dimensions.account_status)}
									</Badge>
								</TableCell>
								<TableCell>
									<Badge color={membershipColor(row.membership.status)}>
										{row.membership.status_label}
									</Badge>
								</TableCell>
								<TableCell>
									<Badge color={murguiaColor(row.murguia.status)}>
										{murguiaLabel(row.murguia.status)}
									</Badge>
								</TableCell>
								<TableCell className="min-w-48">
									<FlagList flags={row.dimensions.flags} />
								</TableCell>
								<TableCell className="text-right">
									<Button type="button" outline onClick={() => onView(row)}>
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

function DetailDialog({ row, onClose }) {
	if (!row) return null;

	return (
		<Dialog open={Boolean(row)} onClose={onClose} size="5xl">
			<DialogTitle>{row.source.name || "Detalle de colaborador"}</DialogTitle>
			<DialogBody className="max-h-[75vh] overflow-y-auto">
				<div className="grid gap-6 lg:grid-cols-2">
					<DetailSection title="Datos del Excel" items={[
						["Nombre", row.source.name],
						["Fecha nacimiento", row.source.birth_date],
						["Correo", row.source.email],
						["Empresa", row.source.company],
						["Empleado", row.source.employee],
						["ID ODESSA", row.source.odessa_id],
						["Hoja / fila", `${row.source.sheet} / ${row.source.row}`],
					]} />
					<DetailSection title="Match encontrado" items={[
						["Tipo", row.match.type],
						["Confianza", row.match.confidence],
						["Candidatos", row.match.candidate_count || "—"],
					]} />
					<DetailSection title="FAMEDIC" items={[
						["User ID", row.famedic.user_id],
						["Customer ID", row.famedic.customer_id],
						["Odessa Account ID", row.famedic.odessa_account_id],
						["Nombre", row.famedic.name],
						["Correo", row.famedic.email],
						["Nacimiento", row.famedic.birth_date],
						["Empresa / socio", `${row.famedic.company || "—"} / ${row.famedic.employee || "—"}`],
					]} links={[
						row.famedic.customer_url ? ["Ver cliente", row.famedic.customer_url] : null,
						row.famedic.user_url ? ["Ver usuario", row.famedic.user_url] : null,
					]} />
					<DetailSection title="Membresía" items={[
						["Número", row.membership.identifier],
						["Tipo", row.membership.type],
						["Inicio", row.membership.start_date],
						["Fin", row.membership.end_date],
						["Estado", row.membership.status_label],
						["Última sync Murguía", row.membership.last_sync],
					]} />
					<DetailSection title="Murguía" items={[
						["En reporte", row.murguia.exists_in_report],
						["Estado", murguiaLabel(row.murguia.status)],
						["Clasificación", row.murguia.audit_status],
						["Última acción", row.murguia.last_log_action],
						["Último status", row.murguia.last_log_status],
						["Último log", row.murguia.last_log_date],
					]} />
					<DetailSection title="Comparación" items={[
						["Nombre", row.comparisons.full_name_match],
						["Apellido paterno", row.comparisons.paternal_lastname_match],
						["Apellido materno", row.comparisons.maternal_lastname_match],
						["Nacimiento", row.comparisons.birth_date_match],
						["Correo", row.comparisons.email_status],
						["Empresa", row.comparisons.company_matches],
						["Socio", row.comparisons.partner_matches],
					]} />
				</div>

				<div className="mt-6 grid gap-4 lg:grid-cols-2">
					<ListPanel title="Evidencia" items={row.match.evidence} />
					<ListPanel title="Calidad de datos" items={row.dimensions.flags} empty="Sin alertas" />
					<ListPanel title="Notas de revisión" items={row.review_notes} />
					<ListPanel title="Posibles candidatos" items={row.match.candidates} />
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

function DetailSection({ title, items, links = [] }) {
	return (
		<section className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
			<Subheading level={3}>{title}</Subheading>
			<dl className="mt-3 grid grid-cols-[9rem_1fr] gap-x-3 gap-y-2 text-sm">
				{items.map(([label, value]) => (
					<div key={label} className="contents">
						<dt className="text-zinc-500">{label}</dt>
						<dd className="break-words">{value || "—"}</dd>
					</div>
				))}
			</dl>
			{links.filter(Boolean).length > 0 ? (
				<div className="mt-4 flex flex-wrap gap-2">
					{links.filter(Boolean).map(([label, href]) => (
						<Button key={href} href={href} outline>
							{label}
						</Button>
					))}
				</div>
			) : null}
		</section>
	);
}

function ListPanel({ title, items, empty = "Sin datos" }) {
	return (
		<section className="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
			<Subheading level={3}>{title}</Subheading>
			{items.length > 0 ? (
				<ul className="mt-3 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
					{items.map((item, index) => (
						<li key={`${item}-${index}`}>{item}</li>
					))}
				</ul>
			) : (
				<Text className="mt-3 text-sm text-zinc-500">{empty}</Text>
			)}
		</section>
	);
}

function FlagList({ flags }) {
	if (!flags.length) return <span className="text-xs text-zinc-400">—</span>;

	return (
		<div className="flex flex-wrap gap-1">
			{flags.slice(0, 3).map((flag) => (
				<Badge key={flag} color={flagColor(flag)}>
					{flag}
				</Badge>
			))}
			{flags.length > 3 ? <Badge color="zinc">+{flags.length - 3}</Badge> : null}
		</div>
	);
}

function EmptyState() {
	return (
		<div className="rounded-xl border border-dashed border-zinc-300 bg-white p-10 text-center dark:border-zinc-700 dark:bg-zinc-900">
			<FunnelIcon className="mx-auto size-8 text-zinc-400" />
			<Text className="mt-3 font-medium">Sin análisis cargado</Text>
			<Text className="mx-auto mt-1 max-w-xl text-sm text-zinc-500">
				Sube el reporte de colaboradores y, si aplica, el padrón Murguía para
				ver resumen, discrepancias, evidencia de matching y exportación.
			</Text>
		</div>
	);
}

function Notice({ color, children }) {
	const classes =
		color === "red"
			? "border-red-200 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/40 dark:text-red-100"
			: "border-emerald-200 bg-emerald-50 text-emerald-900 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-100";

	return <div className={`rounded-lg border px-4 py-3 text-sm ${classes}`}>{children}</div>;
}

function clearPreview() {
	if (confirm("¿Eliminar el preview de conciliación actual?")) {
		router.delete(route("admin.odessa.reconciliation.clear"));
	}
}

function matchesQuickFilter(row, key) {
	switch (key) {
		case "complete":
			return row.dimensions.final_status === "REGISTRO_COMPLETO";
		case "email_different":
			return row.comparisons.email_status === "email_different";
		case "without_membership":
			return row.dimensions.final_status === "AFILIADO_SIN_MEMBRESIA";
		case "expired_membership":
			return row.dimensions.final_status === "MEMBRESIA_VENCIDA";
		case "not_found":
			return row.dimensions.final_status === "NO_REGISTRADO_EN_FAMEDIC";
		case "manual_review":
			return row.dimensions.final_status === "REVISAR_MANUALMENTE";
		case "famedic_no_murguia":
			return row.murguia.status === "FAMEDIC_NO_MURGUIA";
		case "murguia_no_famedic":
			return row.murguia.status === "MURGUIA_NO_FAMEDIC";
		default:
			return true;
	}
}

function membershipLabel(value) {
	return {
		ACTIVE: "Activa",
		EXPIRED: "Vencida",
		FUTURE: "Futura",
		MISSING: "Sin suscripción",
		DELETED_ONLY: "Solo eliminada",
	}[value] || value;
}

function murguiaLabel(value) {
	return {
		FAMEDIC_Y_MURGUIA: "En Mayo",
		FAMEDIC_NO_MURGUIA: "No en Mayo",
		MURGUIA_NO_FAMEDIC: "Solo Murguía",
	}[value] || "Sin Murguía";
}

function accountLabel(value) {
	return {
		ODESSA_ACTIVE: "Activa",
		NO_ACCOUNT: "Sin cuenta",
		NON_ODESSA_CUSTOMER: "No ODESSA",
	}[value] || value || "Sin cuenta";
}

function textColor(color) {
	return {
		emerald: "text-emerald-600",
		amber: "text-amber-600",
		red: "text-red-600",
		green: "text-green-600",
		orange: "text-orange-600",
		sky: "text-sky-600",
	}[color] || "text-zinc-900 dark:text-zinc-100";
}

function matchColor(value) {
	if (value === "MATCH_CONFIRMED_ODESSA_ID") return "green";
	if (value === "MATCH_CONFIRMED_COMPANY_PARTNER") return "emerald";
	if (value === "MATCH_CONFIRMED_EMAIL") return "sky";
	if (value === "MATCH_PROBABLE_IDENTITY") return "amber";
	return "zinc";
}

function membershipColor(value) {
	if (value === "ACTIVE") return "green";
	if (value === "EXPIRED" || value === "DELETED_ONLY") return "red";
	if (value === "MISSING") return "amber";
	return "zinc";
}

function accountColor(value) {
	if (value === "ODESSA_ACTIVE") return "green";
	if (value === "NON_ODESSA_CUSTOMER") return "amber";
	return "zinc";
}

function murguiaColor(value) {
	if (value === "FAMEDIC_Y_MURGUIA") return "green";
	if (value === "FAMEDIC_NO_MURGUIA") return "amber";
	if (value === "MURGUIA_NO_FAMEDIC") return "red";
	return "zinc";
}

function flagColor(flag) {
	if (flag.includes("DISCREPANCIA")) return "red";
	if (flag.includes("EMAIL")) return "orange";
	if (flag.includes("MEMBERSHIP") || flag.includes("SUBSCRIPTION")) return "amber";
	return "violet";
}
