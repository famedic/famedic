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
import { Button } from "@/Components/Catalyst/button";

export default function EcommerceDistribution({ rows = [] }) {
	if (!rows.length) {
		return (
			<section className="space-y-3">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Distribución por línea de negocio
				</h2>
				<EmptyListCard
					heading="Sin ventas"
					message="No hay GMV en el periodo seleccionado."
				/>
			</section>
		);
	}

	return (
		<section className="space-y-3">
			<div className="flex flex-wrap items-center gap-2">
				<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					Distribución por línea de negocio
				</h2>
				<AnalyticsTruthBadge truth="disponible" />
			</div>

			<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<Table bleed className="[--gutter:theme(spacing.6)]" dense>
					<TableHead>
						<TableRow>
							<TableHeader>Línea</TableHeader>
							<TableHeader>Pedidos</TableHeader>
							<TableHeader>GMV</TableHeader>
							<TableHeader>Participación</TableHeader>
							<TableHeader className="text-right">Detalle</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{rows.map((row) => (
							<TableRow key={row.id}>
								<TableCell className="font-medium">{row.label}</TableCell>
								<TableCell className="tabular-nums">
									{row.orders_label}
								</TableCell>
								<TableCell className="tabular-nums">{row.gmv_label}</TableCell>
								<TableCell className="tabular-nums">
									{row.share_percent}%
								</TableCell>
								<TableCell className="text-right">
									{row.href ? (
										<Button href={row.href} outline>
											Abrir
										</Button>
									) : (
										<span className="text-xs text-zinc-400">—</span>
									)}
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</div>
		</section>
	);
}
