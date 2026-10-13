import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import SearchInput from "@/Components/Admin/SearchInput";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { Link, router, useForm } from "@inertiajs/react";
import { useEffect, useMemo } from "react";

const statusColors = {
	AUTO_CONSOLIDATED: "emerald",
	FALLBACK_ORIGINAL: "amber",
	generated: "emerald",
	stale: "zinc",
	failed: "red",
	succeeded: "emerald",
	queued: "sky",
	processing: "sky",
	not_run: "zinc",
	v3: "violet",
	legacy: "zinc",
	pending: "amber",
};

function formatDate(value) {
	if (!value) return "—";
	return new Date(value).toLocaleString("es-MX");
}

function Select({ value, onChange, options = [], placeholder }) {
	return (
		<select
			className="h-10 rounded-md border border-zinc-950/10 bg-white px-3 text-sm text-zinc-900 shadow-sm dark:border-white/10 dark:bg-zinc-900 dark:text-white"
			value={value || ""}
			onChange={(event) => onChange(event.target.value)}
		>
			<option value="">{placeholder}</option>
			{options.map((option) => (
				<option key={option.value} value={option.value}>
					{option.label}
				</option>
			))}
		</select>
	);
}

function MetricGrid({ metrics = [] }) {
	return (
		<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
			{metrics.map((metric) => (
				<div
					key={metric.id}
					className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900"
				>
					<div className="text-xs font-medium uppercase tracking-wide text-zinc-500">
						{metric.label}
					</div>
					<div className="mt-2 text-2xl font-semibold text-zinc-950 dark:text-white">
						{metric.value}
					</div>
				</div>
			))}
		</div>
	);
}

function MonitorFilters({ filters, options }) {
	const { data, setData } = useForm({
		search: filters.search || "",
		date_from: filters.date_from || "",
		date_to: filters.date_to || "",
		brand: filters.brand || "",
		technical_status: filters.technical_status || "",
		decision_status: filters.decision_status || "",
		fallback_category: filters.fallback_category || "",
		fallback_reason: filters.fallback_reason || "",
		openai_status: filters.openai_status || "",
		needs_provider_review: filters.needs_provider_review || "",
		rule: filters.rule || "",
		mode: filters.mode || "",
	});

	const query = useMemo(() => {
		return Object.fromEntries(
			Object.entries(data).filter(([, value]) => value !== "" && value != null),
		);
	}, [data]);

	useEffect(() => {
		const timeout = setTimeout(() => {
			router.get(route("admin.laboratory-preparation.monitor.index"), query, {
				preserveState: true,
				replace: true,
			});
		}, 400);

		return () => clearTimeout(timeout);
	}, [query]);

	const clear = () => {
		router.get(route("admin.laboratory-preparation.monitor.index"), {}, {
			preserveState: true,
			replace: true,
		});
	};

	return (
		<div className="rounded-lg border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
			<div className="grid gap-3 lg:grid-cols-4">
				<SearchInput
					value={data.search}
					onChange={(value) => setData("search", value)}
					placeholder="ID, folio o estudio"
				/>
				<Input
					type="date"
					value={data.date_from}
					onChange={(event) => setData("date_from", event.target.value)}
				/>
				<Input
					type="date"
					value={data.date_to}
					onChange={(event) => setData("date_to", event.target.value)}
				/>
				<Select
					value={data.brand}
					onChange={(value) => setData("brand", value)}
					options={options.brands}
					placeholder="Laboratorio"
				/>
				<Select
					value={data.mode}
					onChange={(value) => setData("mode", value)}
					options={options.modes}
					placeholder="Modo"
				/>
				<Select
					value={data.technical_status}
					onChange={(value) => setData("technical_status", value)}
					options={options.technicalStatuses}
					placeholder="Estado técnico"
				/>
				<Select
					value={data.decision_status}
					onChange={(value) => setData("decision_status", value)}
					options={options.decisionStatuses}
					placeholder="Decisión"
				/>
				<Select
					value={data.fallback_category}
					onChange={(value) => setData("fallback_category", value)}
					options={options.fallbackCategories}
					placeholder="Categoría fallback"
				/>
				<Input
					value={data.fallback_reason}
					onChange={(event) => setData("fallback_reason", event.target.value)}
					placeholder="Motivo fallback"
				/>
				<Select
					value={data.openai_status}
					onChange={(value) => setData("openai_status", value)}
					options={options.openAiStatuses}
					placeholder="Estado OpenAI"
				/>
				<Select
					value={data.needs_provider_review}
					onChange={(value) => setData("needs_provider_review", value)}
					options={[
						{ value: "true", label: "Requiere revisión" },
						{ value: "false", label: "Sin revisión" },
					]}
					placeholder="Revisión"
				/>
				<Select
					value={data.rule}
					onChange={(value) => setData("rule", value)}
					options={(options.rules || []).map((rule) => ({ value: rule, label: rule }))}
					placeholder="Regla aplicada"
				/>
			</div>
			<div className="mt-3 flex justify-end">
				<Button plain type="button" onClick={clear}>
					Limpiar filtros
				</Button>
			</div>
		</div>
	);
}

function StatusBadge({ value }) {
	if (!value) return <span className="text-zinc-400">—</span>;

	return <Badge color={statusColors[value] || "zinc"}>{value}</Badge>;
}

function MonitorTable({ purchases }) {
	return (
		<PaginatedTable paginatedData={purchases}>
			<Table>
				<TableHead>
					<TableRow>
						<TableHeader>Compra</TableHeader>
						<TableHeader>Folio</TableHeader>
						<TableHeader>Laboratorio</TableHeader>
						<TableHeader>Compra</TableHeader>
						<TableHeader>Generación</TableHeader>
						<TableHeader>Modo</TableHeader>
						<TableHeader>Estado</TableHeader>
						<TableHeader>Decisión</TableHeader>
						<TableHeader>Fallback</TableHeader>
						<TableHeader>Reglas</TableHeader>
						<TableHeader>OpenAI</TableHeader>
						<TableHeader>Revisión</TableHeader>
						<TableHeader></TableHeader>
					</TableRow>
				</TableHead>
				<TableBody>
					{purchases.data.map((purchase) => (
						<TableRow key={purchase.id}>
							<TableCell>
								<Link
									className="font-medium text-blue-700 hover:underline dark:text-blue-300"
									href={purchase.admin_purchase_url}
								>
									#{purchase.id}
								</Link>
							</TableCell>
							<TableCell>{purchase.folio}</TableCell>
							<TableCell>{purchase.brand}</TableCell>
							<TableCell>{formatDate(purchase.created_at)}</TableCell>
							<TableCell>{formatDate(purchase.generated_at)}</TableCell>
							<TableCell><StatusBadge value={purchase.mode} /></TableCell>
							<TableCell><StatusBadge value={purchase.technical_status} /></TableCell>
							<TableCell><StatusBadge value={purchase.decision_status} /></TableCell>
							<TableCell className="max-w-64 truncate">{purchase.fallback_reason || "—"}</TableCell>
							<TableCell>
								<div className="flex flex-wrap gap-1">
									{purchase.rules_applied?.length ? purchase.rules_applied.map((rule) => (
										<Badge key={rule} color="slate">{rule}</Badge>
									)) : "—"}
								</div>
							</TableCell>
							<TableCell><StatusBadge value={purchase.openai_status} /></TableCell>
							<TableCell>
								{purchase.needs_provider_review ? (
									<Badge color="amber">Sí</Badge>
								) : (
									<span className="text-zinc-400">No</span>
								)}
							</TableCell>
							<TableCell>
								<Button outline href={purchase.detail_url}>
									Ver detalle
								</Button>
							</TableCell>
						</TableRow>
					))}
				</TableBody>
			</Table>
		</PaginatedTable>
	);
}

export default function Monitor({
	filters,
	filterOptions,
	metrics,
	purchases,
	universeNote,
	featureFlags,
}) {
	return (
		<AdminLayout title="Monitor de Indicaciones IA">
			<div className="space-y-6 pb-8">
				<div className="flex flex-wrap items-start justify-between gap-3">
					<div>
						<Heading>Monitor de Indicaciones IA</Heading>
						<p className="mt-1 text-sm text-zinc-500">{universeNote}</p>
					</div>
					<div className="flex flex-wrap gap-2">
						<Badge color={featureFlags.deterministic_v3_enabled ? "emerald" : "zinc"}>
							V3 {featureFlags.deterministic_v3_enabled ? "ON" : "OFF"}
						</Badge>
						<Badge color="zinc">Shadow UI N/D</Badge>
					</div>
				</div>
				<MetricGrid metrics={metrics} />
				<MonitorFilters filters={filters} options={filterOptions} />
				{purchases.data.length ? (
					<MonitorTable purchases={purchases} />
				) : (
					<div className="rounded-lg border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">
						No hay compras para los filtros seleccionados.
					</div>
				)}
			</div>
		</AdminLayout>
	);
}
