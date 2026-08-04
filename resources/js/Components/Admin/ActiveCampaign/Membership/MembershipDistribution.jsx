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
import { Text } from "@/Components/Catalyst/text";

function DistTable({ title, badge, rows, empty }) {
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
							<TableHeader>Tipo</TableHeader>
							<TableHeader>Altas</TableHeader>
							<TableHeader>Ingresos</TableHeader>
							<TableHeader>Dato</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={row.id}>
								<TableCell>
									<div className="font-medium">{row.label}</div>
									{row.hint ? (
										<Text className="mt-0.5 max-w-[16rem] text-[11px] text-zinc-400">
											{row.hint}
										</Text>
									) : null}
								</TableCell>
								<TableCell className="tabular-nums">
									{row.total_label}
								</TableCell>
								<TableCell className="tabular-nums">
									{row.revenue_label}
								</TableCell>
								<TableCell>
									<AnalyticsTruthBadge truth={row.truth} />
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</div>
	);
}

export default function MembershipDistribution({ distribution }) {
	const byEnum = distribution?.by_enum || [];
	const byPlan = distribution?.by_plan || [];

	return (
		<section className="space-y-4">
			<div>
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Distribución por tipo
				</h2>
				<p className="text-xs text-zinc-500">
					Tipos nativos del dominio y buckets Mensual / Semestral / Anual /
					Corporativa (proxy por duración o institutional).
				</p>
			</div>

			<div className="grid gap-4 xl:grid-cols-2">
				<DistTable
					title="Tipos del dominio"
					badge={<Badge color="emerald">Disponible</Badge>}
					rows={byEnum}
					empty="Sin altas en el periodo."
				/>
				<DistTable
					title="Planes (vista producto)"
					badge={<Badge color="amber">Proxy / Instrumentación</Badge>}
					rows={byPlan}
					empty="Sin clasificación disponible."
				/>
			</div>
		</section>
	);
}
