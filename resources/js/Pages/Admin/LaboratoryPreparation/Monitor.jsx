import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import { Link, router } from "@inertiajs/react";
import { useEffect, useState } from "react";
import { StatusBadge } from "./MonitorComponents";
import {
	advancedFilterKeys,
	filterValues,
	filterQuery,
	primaryMetricIds,
	formatDate,
} from "./monitorPresentation";

const inputClass =
	"block w-full min-w-0 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-950 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white";
function Metrics({ metrics }) {
	const byId = Object.fromEntries(
		metrics.map((metric) => [metric.id, metric]),
	);
	const card = (metric) => (
		<div
			key={metric.id}
			className="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
		>
			<dt className="text-xs font-medium text-zinc-500 [overflow-wrap:anywhere] dark:text-zinc-400">
				{metric.label}
			</dt>
			<dd className="mt-2 text-2xl font-semibold">{metric.value}</dd>
		</div>
	);
	return (
		<section aria-label="Indicadores del filtro actual">
			<p className="mb-3 text-sm text-zinc-500 dark:text-zinc-400">
				Resultados del filtro actual · Los indicadores pueden
				superponerse.
			</p>
			<dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
				{primaryMetricIds.map((id) => byId[id] && card(byId[id]))}
			</dl>
			<details className="mt-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
				<summary className="cursor-pointer text-sm font-medium">
					Más métricas
				</summary>
				<dl className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
					{metrics
						.filter(
							(metric) => !primaryMetricIds.includes(metric.id),
						)
						.map(card)}
				</dl>
			</details>
		</section>
	);
}
function MonitorFilters({ filters, options, errors = {} }) {
	const [values, setValues] = useState(() => filterValues(filters));
	useEffect(() => setValues(filterValues(filters)), [filters]);
	const active = Object.entries(filterQuery(values));
	const advancedCount = advancedFilterKeys.filter(
		(key) => values[key] !== "",
	).length;
	const change = (key) => (event) =>
		setValues((current) => ({ ...current, [key]: event.target.value }));
	const navigate = (data) =>
		router.get(
			route("admin.laboratory-preparation.monitor.index"),
			filterQuery(data),
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	const labels = {
		search: "Compra, folio o estudio",
		date_from: "Desde",
		date_to: "Hasta",
		decision_status: "Decisión",
		brand: "Laboratorio",
		technical_status: "Estado técnico",
		fallback_category: "Categoría de fallback",
		fallback_reason: "Motivo de fallback",
		openai_status: "Estado OpenAI",
		needs_provider_review: "Revisión",
		rule: "Regla aplicada",
		mode: "Modo",
	};
	const input = (key, type = "text") => (
		<label className="block min-w-0 text-sm" key={key}>
			<span className="mb-1 block text-zinc-600 dark:text-zinc-300">
				{labels[key]}
			</span>
			<input
				className={inputClass}
				type={type}
				value={values[key]}
				onChange={change(key)}
			/>
		</label>
	);
	const select = (key, choices = []) => (
		<label className="block min-w-0 text-sm" key={key}>
			<span className="mb-1 block text-zinc-600 dark:text-zinc-300">
				{labels[key]}
			</span>
			<select
				className={inputClass}
				value={values[key]}
				onChange={change(key)}
			>
				<option value="">Todos</option>
				{choices.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
		</label>
	);
	return (
		<form
			onSubmit={(event) => {
				event.preventDefault();
				navigate(values);
			}}
			className="min-w-0 space-y-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900"
			aria-label="Filtros del monitor"
		>
			{Object.keys(errors).length > 0 && (
				<ul
					role="alert"
					className="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-950"
				>
					{Object.entries(errors).map(([key, message]) => (
						<li key={key}>{message}</li>
					))}
				</ul>
			)}
			<div className="grid min-w-0 gap-3 sm:grid-cols-2 xl:grid-cols-5">
				{input("search")}
				{input("date_from", "date")}
				{input("date_to", "date")}
				{select("decision_status", options.decisionStatuses)}
				{select("brand", options.brands)}
			</div>
			<details
				key={JSON.stringify(filters)}
				open={
					advancedCount > 0 ||
					advancedFilterKeys.some((key) => errors[key]) ||
					undefined
				}
			>
				<summary className="cursor-pointer text-sm font-medium">
					Filtros avanzados{" "}
					{advancedCount > 0 && `(${advancedCount} activos)`}
				</summary>
				<div className="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
					{select("technical_status", options.technicalStatuses)}
					{select("openai_status", options.openAiStatuses)}
					{select("fallback_category", options.fallbackCategories)}
					{input("fallback_reason")}
					{select("needs_provider_review", [
						{ value: "true", label: "Requiere revisión" },
						{ value: "false", label: "Sin revisión" },
					])}
					{select(
						"rule",
						(options.rules || []).map((value) => ({
							value,
							label: value,
						})),
					)}
					{select("mode", options.modes)}
				</div>
			</details>
			<div className="flex flex-wrap gap-2">
				<Button type="submit">Aplicar filtros</Button>
				<Button
					plain
					type="button"
					onClick={() => {
						const empty = filterValues();
						setValues(empty);
						navigate(empty);
					}}
				>
					Limpiar filtros
				</Button>
			</div>
			{active.length > 0 && (
				<ul
					aria-label="Filtros seleccionados"
					className="flex flex-wrap gap-2"
				>
					{active.map(([key, value]) => (
						<li key={key} className="min-w-0">
							<button
								type="button"
								className="rounded-full border border-zinc-300 px-3 py-1 text-left text-xs [overflow-wrap:anywhere] hover:bg-zinc-100 focus-visible:outline focus-visible:outline-2 dark:hover:bg-zinc-800"
								aria-label={`Quitar filtro ${labels[key]}`}
								onClick={() => {
									const next = { ...values, [key]: "" };
									setValues(next);
									navigate(next);
								}}
							>
								{labels[key]}: {value} ×
							</button>
						</li>
					))}
				</ul>
			)}
		</form>
	);
}
function Identity({ purchase }) {
	return (
		<div className="space-y-1">
			<Link
				className="font-semibold text-blue-700 underline-offset-2 hover:underline dark:text-blue-300"
				href={purchase.detail_url}
			>
				Compra #{purchase.id}
			</Link>
			<p className="text-xs text-zinc-500 dark:text-zinc-400">
				{purchase.folio}
			</p>
			{purchase.deleted_at && (
				<Badge color="zinc">Compra eliminada</Badge>
			)}
		</div>
	);
}
function Result({ purchase }) {
	return (
		<div className="space-y-2">
			<StatusBadge value={purchase.decision_status} />
			{purchase.needs_provider_review && (
				<div>
					<Badge color="amber">⚑ Revisión requerida</Badge>
				</div>
			)}
		</div>
	);
}
function Technical({ purchase }) {
	return (
		<div className="space-y-2">
			<StatusBadge value={purchase.technical_status} />
			<p className="text-xs text-zinc-500 dark:text-zinc-400">
				OpenAI:{" "}
				<span className="text-zinc-700 dark:text-zinc-200">
					{purchase.openai_status === "not_run" ? (
						"Sin ejecución relevante"
					) : (
						<StatusBadge value={purchase.openai_status} />
					)}
				</span>
			</p>
		</div>
	);
}
function MonitorList({ purchases }) {
	return (
		<PaginatedTable paginatedData={purchases}>
			<div className="hidden min-w-0 md:block">
				<table className="w-full table-fixed text-left text-sm">
					<caption className="sr-only">
						Compras monitoreadas y estado de su preparación
					</caption>
					<thead className="text-xs text-zinc-500 dark:text-zinc-400">
						<tr>
							{[
								"Compra y folio",
								"Laboratorio y fecha",
								"Resultado y revisión",
								"Estado técnico",
								"Detalle",
							].map((label) => (
								<th
									scope="col"
									key={label}
									className="px-2 py-3 font-medium [overflow-wrap:anywhere]"
								>
									{label}
								</th>
							))}
						</tr>
					</thead>
					<tbody className="divide-y divide-zinc-200 dark:divide-zinc-800">
						{purchases.data.map((purchase) => (
							<tr key={purchase.id} className="align-top">
								<td className="px-2 py-4 [overflow-wrap:anywhere]">
									<Identity purchase={purchase} />
								</td>
								<td className="px-2 py-4 [overflow-wrap:anywhere]">
									<p>{purchase.brand}</p>
									<p className="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
										{formatDate(purchase.created_at)}
									</p>
								</td>
								<td className="px-2 py-4 [overflow-wrap:anywhere]">
									<Result purchase={purchase} />
								</td>
								<td className="px-2 py-4 [overflow-wrap:anywhere]">
									<Technical purchase={purchase} />
								</td>
								<td className="px-2 py-4">
									<Link
										href={purchase.detail_url}
										className="inline-block text-sm font-medium text-blue-700 underline [overflow-wrap:anywhere] dark:text-blue-300"
										aria-label={`Ver detalle de compra ${purchase.id}`}
									>
										Ver detalle
									</Link>
								</td>
							</tr>
						))}
					</tbody>
				</table>
			</div>
			<ul
				className="space-y-3 md:hidden"
				aria-label="Compras monitoreadas"
			>
				{purchases.data.map((purchase) => (
					<li
						key={purchase.id}
						className="min-w-0 space-y-4 rounded-xl border border-zinc-200 bg-white p-4 [overflow-wrap:anywhere] dark:border-zinc-800 dark:bg-zinc-900"
					>
						<Identity purchase={purchase} />
						<p className="text-sm">
							{purchase.brand} · {formatDate(purchase.created_at)}
						</p>
						<Result purchase={purchase} />
						<Technical purchase={purchase} />
						<Link
							className="inline-block text-sm font-medium text-blue-700 underline dark:text-blue-300"
							href={purchase.detail_url}
						>
							Ver detalle de compra #{purchase.id}
						</Link>
					</li>
				))}
			</ul>
		</PaginatedTable>
	);
}
export default function Monitor({
	filters,
	filterOptions,
	metrics,
	purchases,
	universeNote,
	semanticsNote,
	featureFlags,
	errors = {},
}) {
	return (
		<AdminLayout title="Monitor de Indicaciones IA">
			<div className="min-w-0 space-y-6 pb-8 text-zinc-950 dark:text-zinc-100">
				<header className="min-w-0">
					<Heading>Monitor de Indicaciones IA</Heading>
					<p className="mt-2 max-w-4xl text-sm text-zinc-500 dark:text-zinc-400">
						{universeNote}
					</p>
					<div className="mt-3 flex flex-wrap gap-2">
						<Badge
							color={
								featureFlags.deterministic_v3_enabled
									? "emerald"
									: "zinc"
							}
						>
							V3{" "}
							{featureFlags.deterministic_v3_enabled
								? "ON"
								: "OFF"}
						</Badge>
						<Badge color="zinc">
							Shadow: sin datos persistidos
						</Badge>
					</div>
				</header>
				<Metrics metrics={metrics} />
				<details className="rounded-xl bg-zinc-100 p-4 text-sm dark:bg-zinc-900">
					<summary className="cursor-pointer font-medium">
						Cómo interpretar los indicadores
					</summary>
					<p className="mt-2">{semanticsNote}</p>
					<p className="mt-2">
						Una generación v3 aún en cola puede no tener una
						ejecución persistida; el monitor no inventa ese estado.
					</p>
				</details>
				<MonitorFilters
					filters={filters}
					options={filterOptions}
					errors={errors}
				/>
				{purchases.data.length ? (
					<MonitorList purchases={purchases} />
				) : (
					<p
						role="status"
						className="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm"
					>
						No hay compras para los filtros seleccionados.
					</p>
				)}
			</div>
		</AdminLayout>
	);
}
