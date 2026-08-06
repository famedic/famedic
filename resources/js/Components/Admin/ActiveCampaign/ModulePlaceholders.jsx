import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ChartBarIcon,
	PresentationChartLineIcon,
	TableCellsIcon,
} from "@heroicons/react/24/outline";

const TRUTH = {
	disponible: { label: "Disponible", color: "emerald" },
	proxy: { label: "Proxy", color: "amber" },
	proximamente: { label: "Próximamente", color: "zinc" },
	proxima_fase: { label: "Disponible en la siguiente fase", color: "sky" },
	instrumentacion: { label: "Requiere sincronización", color: "violet" },
};

export function TruthBadge({ truth }) {
	const meta = TRUTH[truth] || TRUTH.proximamente;
	return <Badge color={meta.color}>{meta.label}</Badge>;
}

export function PhaseBanner({ phaseLabel, phase }) {
	const meta = TRUTH[phase] || TRUTH.proxima_fase;

	return (
		<div className="rounded-xl border border-dashed border-zinc-300 bg-zinc-50/70 px-4 py-3 dark:border-zinc-600 dark:bg-zinc-900/50">
			<div className="flex flex-wrap items-center gap-2">
				<Badge color={meta.color}>{phaseLabel || meta.label}</Badge>
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					Estás navegando la estructura del módulo. Los datos se habilitarán por
					pantalla en fases posteriores.
				</Text>
			</div>
		</div>
	);
}

export function MetricCardPlaceholders({ cards = [] }) {
	if (!cards.length) return null;

	return (
		<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6">
			{cards.map((card) => (
				<div
					key={card.label}
					className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="flex items-start justify-between gap-2">
						<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
							{card.label}
						</p>
						<TruthBadge truth={card.truth} />
					</div>
					<p className="mt-3 text-2xl font-semibold tabular-nums text-zinc-300 dark:text-zinc-600">
						—
					</p>
					<p className="mt-1 text-xs text-zinc-400">Sin datos todavía</p>
				</div>
			))}
		</div>
	);
}

export function ChartPlaceholders({ charts = [] }) {
	if (!charts.length) return null;

	return (
		<div className="grid gap-4 lg:grid-cols-2">
			{charts.map((chart) => (
				<ChartCard key={chart.title} title={chart.title}>
					<div className="mb-3">
						<TruthBadge truth={chart.truth} />
					</div>
					<div className="flex h-48 flex-col items-center justify-center rounded-lg border border-dashed border-zinc-200 bg-zinc-50/50 dark:border-zinc-700 dark:bg-zinc-950/40">
						<PresentationChartLineIcon className="size-10 text-zinc-300 dark:text-zinc-600" />
						<p className="mt-3 text-sm font-medium text-zinc-500 dark:text-zinc-400">
							Gráfica placeholder
						</p>
						<p className="mt-1 max-w-xs text-center text-xs text-zinc-400">
							Se conectará cuando esta pantalla entre en implementación.
						</p>
					</div>
				</ChartCard>
			))}
		</div>
	);
}

export function TablePlaceholders({ tables = [] }) {
	if (!tables.length) return null;

	return (
		<div className="grid gap-4 xl:grid-cols-2">
			{tables.map((table) => (
				<ChartCard key={table.title} title={table.title}>
					<div className="mb-3 flex items-center gap-2">
						<TableCellsIcon className="size-4 text-zinc-400" />
						<Badge color="zinc">Placeholder</Badge>
					</div>
					<Table dense className="[--gutter:theme(spacing.4)]">
						<TableHead>
							<TableRow>
								{(table.columns || []).map((column) => (
									<TableHeader key={column}>{column}</TableHeader>
								))}
							</TableRow>
						</TableHead>
						<TableBody>
							{[1, 2, 3].map((row) => (
								<TableRow key={row}>
									{(table.columns || []).map((column, index) => (
										<TableCell key={`${column}-${row}`}>
											<span className="inline-block h-3 w-20 rounded bg-zinc-100 dark:bg-zinc-800" />
											{index === 0 ? (
												<span className="sr-only">Fila de ejemplo</span>
											) : null}
										</TableCell>
									))}
								</TableRow>
							))}
							<TableRow>
								<TableCell colSpan={(table.columns || []).length || 1}>
									<Text className="py-4 text-center text-xs text-zinc-500 dark:text-zinc-400">
										Tabla lista. Sin filas reales todavía.
									</Text>
								</TableCell>
							</TableRow>
						</TableBody>
					</Table>
				</ChartCard>
			))}
		</div>
	);
}

export function FunnelPlaceholder() {
	const steps = ["Registro", "Carrito", "Abandono", "Compra", "Retención"];

	return (
		<ChartCard title="Embudo" description="Estructura visual reservada para conversión.">
			<div className="mb-3">
				<TruthBadge truth="proxima_fase" />
			</div>
			<div className="space-y-2">
				{steps.map((step, index) => (
					<div
						key={step}
						className="flex items-center justify-between rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-950/40"
						style={{ width: `${100 - index * 12}%`, minWidth: "48%" }}
					>
						<span className="text-sm font-medium text-zinc-700 dark:text-zinc-200">
							{step}
						</span>
						<span className="text-sm tabular-nums text-zinc-300 dark:text-zinc-600">—</span>
					</div>
				))}
			</div>
		</ChartCard>
	);
}

export function JourneyPlaceholder() {
	const nodes = [
		"Registro",
		"Paciente creado",
		"Crédito asignado",
		"Carrito abandonado",
		"Compra",
	];

	return (
		<ChartCard title="Timeline" description="Customer journey de ejemplo (sin datos).">
			<div className="mb-3">
				<TruthBadge truth="proxima_fase" />
			</div>
			<ol className="space-y-4 border-l border-zinc-200 pl-4 dark:border-zinc-700">
				{nodes.map((node) => (
					<li key={node} className="relative">
						<span className="absolute -left-[1.35rem] top-1 size-2.5 rounded-full bg-zinc-300 dark:bg-zinc-600" />
						<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">{node}</p>
						<p className="text-xs text-zinc-400">Pendiente de instrumentar</p>
					</li>
				))}
			</ol>
		</ChartCard>
	);
}

export function SettingsSectionsPlaceholder() {
	const sections = [
		"Estado general",
		"Cupones y créditos",
		"Carritos abandonados",
		"Catálogo de tags",
		"Catálogo de campos",
		"Fuentes de datos",
	];

	return (
		<div className="space-y-3">
			{sections.map((section) => (
				<div
					key={section}
					className="rounded-xl border border-zinc-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900"
				>
					<div className="flex flex-wrap items-center justify-between gap-2">
						<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							{section}
						</p>
						<div className="flex gap-2">
							<Badge color="zinc">Solo lectura</Badge>
							<Button disabled outline>
								Editar
							</Button>
						</div>
					</div>
					<p className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
						Controles y valores se mostrarán aquí en la siguiente fase.
					</p>
				</div>
			))}
		</div>
	);
}

export function HealthChecklistPlaceholder() {
	const items = [
		{ label: "Integración global", truth: "disponible" },
		{ label: "Cupones habilitados", truth: "disponible" },
		{ label: "Última sync reciente", truth: "disponible" },
		{ label: "Webhooks inbound", truth: "instrumentacion" },
		{ label: "Cobertura sync compras", truth: "instrumentacion" },
		{ label: "Paridad QA / Prod", truth: "proxima_fase" },
	];

	return (
		<ChartCard title="Checklist de salud">
			<ul className="space-y-3">
				{items.map((item) => (
					<li
						key={item.label}
						className="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-zinc-100 px-3 py-2 dark:border-zinc-800"
					>
						<div className="flex items-center gap-2">
							<ChartBarIcon className="size-4 text-zinc-400" />
							<span className="text-sm text-zinc-800 dark:text-zinc-100">
								{item.label}
							</span>
						</div>
						<TruthBadge truth={item.truth} />
					</li>
				))}
			</ul>
		</ChartCard>
	);
}
