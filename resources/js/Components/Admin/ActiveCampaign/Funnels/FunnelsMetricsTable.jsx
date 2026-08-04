import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import AnalyticsTruthBadge from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsTruthBadge";
import {
	ArrowTrendingDownIcon,
	ArrowTrendingUpIcon,
	MinusIcon,
} from "@heroicons/react/16/solid";

function TrendIcon({ trend }) {
	if (trend === "up") {
		return <ArrowTrendingUpIcon className="inline size-3.5 text-emerald-600" />;
	}
	if (trend === "down") {
		return <ArrowTrendingDownIcon className="inline size-3.5 text-rose-600" />;
	}
	if (trend === "flat") {
		return <MinusIcon className="inline size-3.5 text-zinc-400" />;
	}
	return null;
}

export default function FunnelsMetricsTable({ metrics = [] }) {
	return (
		<section className="space-y-3">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Tabla de métricas
				</h2>
				<p className="text-xs text-zinc-500">
					Conversión, abandono, tiempo y valor: honestos cuando aún no existen.
				</p>
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Etapa</TableHeader>
							<TableHeader>Usuarios</TableHeader>
							<TableHeader>Conversión</TableHeader>
							<TableHeader className="hidden lg:table-cell">
								Abandono
							</TableHeader>
							<TableHeader className="hidden xl:table-cell">
								Tiempo prom.
							</TableHeader>
							<TableHeader className="hidden md:table-cell">
								Valor $
							</TableHeader>
							<TableHeader>Vs ant.</TableHeader>
							<TableHeader>Tendencia</TableHeader>
							<TableHeader>Dato</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{metrics.map((row) => (
							<TableRow key={row.id}>
								<TableCell className="font-medium">
									<div>{row.stage}</div>
									{row.hint ? (
										<p className="mt-0.5 max-w-[14rem] text-[11px] font-normal text-zinc-400">
											{row.hint}
										</p>
									) : null}
								</TableCell>
								<TableCell className="tabular-nums">{row.users}</TableCell>
								<TableCell>
									<span className="text-sm">{row.conversion}</span>
								</TableCell>
								<TableCell className="hidden lg:table-cell">
									{row.abandonment}
								</TableCell>
								<TableCell className="hidden xl:table-cell">
									{row.avg_time}
								</TableCell>
								<TableCell className="hidden md:table-cell">
									{row.economic_value}
								</TableCell>
								<TableCell className="tabular-nums text-sm">
									{row.vs_previous}
								</TableCell>
								<TableCell>
									<TrendIcon trend={row.trend} />
								</TableCell>
								<TableCell>
									<AnalyticsTruthBadge truth={row.truth} />
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</section>
	);
}
