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

export default function LaboratoryTopLabs({ rows = [] }) {
	if (!rows.length) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Top laboratorios
				</h2>
				<EmptyListCard
					heading="Sin órdenes"
					message="No hay compras de laboratorio en el periodo."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Top laboratorios
				</h2>
				<AnalyticsTruthBadge truth="disponible" />
			</div>
			<p className="text-xs text-zinc-500">
				Ingresos, órdenes, crecimiento vs periodo anterior y participación.
			</p>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Laboratorio</TableHeader>
							<TableHeader>Ingresos</TableHeader>
							<TableHeader>Órdenes</TableHeader>
							<TableHeader>Crecimiento</TableHeader>
							<TableHeader>Participación</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={row.id}>
								<TableCell className="font-medium">{row.label}</TableCell>
								<TableCell className="tabular-nums">
									{row.revenue_label}
								</TableCell>
								<TableCell className="tabular-nums">
									{row.orders_label}
								</TableCell>
								<TableCell>
									<Trend growth={row.growth} />
								</TableCell>
								<TableCell className="tabular-nums">
									{row.share_percent}%
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</section>
	);
}
