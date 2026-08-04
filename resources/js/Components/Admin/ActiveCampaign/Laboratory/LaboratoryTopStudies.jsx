import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import { Badge } from "@/Components/Catalyst/badge";
import {
	ArrowTrendingDownIcon,
	ArrowTrendingUpIcon,
	MinusIcon,
} from "@heroicons/react/16/solid";

function Trend({ growth }) {
	const direction = growth?.direction;
	if (direction === "up") {
		return (
			<span className="inline-flex items-center gap-1 text-emerald-600">
				<ArrowTrendingUpIcon className="size-3.5" />
				{growth.label}
			</span>
		);
	}
	if (direction === "down") {
		return (
			<span className="inline-flex items-center gap-1 text-rose-600">
				<ArrowTrendingDownIcon className="size-3.5" />
				{growth.label}
			</span>
		);
	}
	return (
		<span className="inline-flex items-center gap-1 text-zinc-400">
			<MinusIcon className="size-3.5" />
			{growth?.label || "—"}
		</span>
	);
}

function StudiesTable({ title, badge, rows, empty }) {
	if (!rows?.length) {
		return (
			<div className="space-y-2">
				<div className="flex items-center gap-2">
					<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
						{title}
					</h3>
					{badge}
				</div>
				<EmptyListCard heading="Sin datos" message={empty} />
			</div>
		);
	}

	return (
		<div className="space-y-2">
			<div className="flex items-center gap-2">
				<h3 className="text-xs font-semibold uppercase tracking-wide text-zinc-400">
					{title}
				</h3>
				{badge}
			</div>
			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Estudio</TableHeader>
							<TableHeader>Cantidad</TableHeader>
							<TableHeader>Ingreso</TableHeader>
							<TableHeader>Crecimiento</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={`${title}-${row.id}-${row.name}`}>
								<TableCell className="max-w-[16rem] font-medium">
									<span className="line-clamp-2">{row.name}</span>
								</TableCell>
								<TableCell className="tabular-nums">
									{row.quantity_label}
								</TableCell>
								<TableCell className="tabular-nums">
									{row.revenue_label}
								</TableCell>
								<TableCell>
									<Trend growth={row.growth} />
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</div>
	);
}

export default function LaboratoryTopStudies({ topStudies }) {
	const byQuantity = topStudies?.by_quantity || [];
	const byRevenue = topStudies?.by_revenue || [];
	const byGrowth = topStudies?.by_growth || [];

	return (
		<section className="space-y-4">
			<div className="flex flex-wrap items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Top estudios
				</h2>
				<AnalyticsTruthBadge truth="disponible" />
			</div>

			<div className="grid gap-4 xl:grid-cols-3">
				<StudiesTable
					title="Más vendidos"
					badge={<Badge color="sky">Cantidad</Badge>}
					rows={byQuantity}
					empty="Sin ítems de compra en el periodo."
				/>
				<StudiesTable
					title="Mayor ingreso"
					badge={<Badge color="lime">Revenue</Badge>}
					rows={byRevenue}
					empty="Sin revenue de estudios."
				/>
				<StudiesTable
					title="Mayor crecimiento"
					badge={<Badge color="amber">Vs ant.</Badge>}
					rows={byGrowth}
					empty="Sin base comparable vs periodo anterior."
				/>
			</div>
		</section>
	);
}
