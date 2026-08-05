import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import Pagination from "@/Components/Pagination";

const BAND_COLOR = {
	excellent: "emerald",
	good: "sky",
	at_risk: "orange",
	critical: "red",
	lost: "zinc",
};

function MiniBar({ value, label }) {
	const color =
		value >= 70 ? "bg-emerald-500" : value >= 40 ? "bg-amber-500" : "bg-rose-500";
	return (
		<div className="min-w-[72px]">
			<div className="mb-0.5 flex justify-between text-[10px] text-zinc-500">
				<span>{label}</span>
				<span>{value}%</span>
			</div>
			<div className="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
				<div className={`h-full rounded-full ${color}`} style={{ width: `${value}%` }} />
			</div>
		</div>
	);
}

export default function HealthCustomersTable({ customers, onSelect }) {
	const rows = customers?.data || [];

	return (
		<ChartCard
			title="Clientes · Health Score"
			description="Clic para abrir el resumen IA y timeline."
		>
			{rows.length === 0 ? (
				<div className="flex h-40 items-center justify-center text-sm text-zinc-400">
					Sin clientes en la muestra filtrada.
				</div>
			) : (
				<div className="overflow-x-auto">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Health</TableHeader>
								<TableHeader>Lead / Prob.</TableHeader>
								<TableHeader>Actividad</TableHeader>
								<TableHeader>LTV</TableHeader>
								<TableHeader>Acción sugerida</TableHeader>
								<TableHeader />
							</TableRow>
						</TableHead>
						<TableBody>
							{rows.map((row) => (
								<TableRow
									key={row.id}
									className="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
									onClick={() => onSelect?.(row)}
								>
									<TableCell>
										<div className="flex items-center gap-3">
											<Avatar
												src={row.avatar}
												initials={(row.name || "?")
													.split(" ")
													.slice(0, 2)
													.map((p) => p[0])
													.join("")
													.toUpperCase()}
												className="size-9"
											/>
											<div className="min-w-0">
												<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
													{row.name}
												</p>
												<p className="truncate text-xs text-zinc-500">
													{row.email || "Sin email"}
												</p>
											</div>
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											<p className="text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
												{row.health_score}
											</p>
											<Badge color={BAND_COLOR[row.band] || "zinc"}>
												{row.band_label}
											</Badge>
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-2">
											<MiniBar value={row.lead_score} label="Lead" />
											<MiniBar
												value={row.probabilities?.purchase || 0}
												label="Compra"
											/>
											<MiniBar
												value={row.probabilities?.churn || 0}
												label="Abandono"
											/>
										</div>
									</TableCell>
									<TableCell>
										<p className="text-xs text-zinc-500">Última actividad</p>
										<p className="text-sm text-zinc-700 dark:text-zinc-300">
											{row.days_since_activity != null
												? `hace ${row.days_since_activity}d`
												: "—"}
										</p>
										<p className="mt-1 text-xs text-zinc-500">Sin comprar</p>
										<p className="text-sm text-zinc-700 dark:text-zinc-300">
											{row.days_since_purchase != null
												? `${row.days_since_purchase}d`
												: "Nunca"}
										</p>
									</TableCell>
									<TableCell>
										<p className="text-sm font-semibold tabular-nums">
											${Number(row.ltv || 0).toLocaleString("es-MX")}
										</p>
										<p className="text-[11px] capitalize text-zinc-400">
											{row.persona}
										</p>
									</TableCell>
									<TableCell>
										<p className="max-w-[220px] text-xs leading-relaxed text-zinc-600 dark:text-zinc-300">
											{(row.recommended_actions || [])[0] || "—"}
										</p>
									</TableCell>
									<TableCell>
										<div onClick={(e) => e.stopPropagation()}>
											<Button plain onClick={() => onSelect?.(row)}>
												Ver 360
											</Button>
										</div>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>
			)}
			{customers?.total > 0 ? (
				<div className="mt-4">
					<Pagination paginatedModels={customers} />
				</div>
			) : null}
		</ChartCard>
	);
}
