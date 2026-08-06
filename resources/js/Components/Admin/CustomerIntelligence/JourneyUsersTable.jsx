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

const RISK_BADGE = {
	high_probability: { color: "emerald", label: "Alta prob." },
	at_risk: { color: "orange", label: "En riesgo" },
	recoverable: { color: "sky", label: "Recuperable" },
	lost: { color: "red", label: "Perdido" },
	converted: { color: "lime", label: "Convertido" },
};

function ScoreBar({ value, label }) {
	const color =
		value >= 70 ? "bg-emerald-500" : value >= 40 ? "bg-amber-500" : "bg-rose-500";

	return (
		<div className="min-w-[84px]">
			<div className="mb-1 flex justify-between text-[10px] tabular-nums text-zinc-500">
				<span>{label}</span>
				<span>{value}%</span>
			</div>
			<div className="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
				<div className={`h-full rounded-full ${color}`} style={{ width: `${value}%` }} />
			</div>
		</div>
	);
}

export default function JourneyUsersTable({ users, onSelect }) {
	const rows = users?.data || [];

	return (
		<ChartCard
			title="Top usuarios del journey"
			description="Clic en una fila para abrir el timeline completo."
		>
			{rows.length === 0 ? (
				<div className="flex h-40 items-center justify-center text-sm text-zinc-400">
					Sin usuarios en el cohort filtrado.
				</div>
			) : (
				<div className="overflow-x-auto">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Registro</TableHeader>
								<TableHeader>Última actividad</TableHeader>
								<TableHeader>Etapa</TableHeader>
								<TableHeader>Detenido</TableHeader>
								<TableHeader>Score / IA</TableHeader>
								<TableHeader>Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{rows.map((user) => {
								const risk = RISK_BADGE[user.risk_segment] || RISK_BADGE.at_risk;
								return (
									<TableRow
										key={user.id}
										className="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
										onClick={() => onSelect?.(user)}
									>
										<TableCell>
											<div className="flex items-center gap-3">
												<Avatar
													src={user.avatar}
													initials={(user.name || "?")
														.split(" ")
														.slice(0, 2)
														.map((p) => p[0])
														.join("")
														.toUpperCase()}
													className="size-9"
												/>
												<div className="min-w-0">
													<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
														{user.name}
													</p>
													<p className="truncate text-xs text-zinc-500">
														{user.email || "Sin email"}
													</p>
												</div>
											</div>
										</TableCell>
										<TableCell>
											<p className="text-sm text-zinc-700 dark:text-zinc-300">
												{user.registered_at}
											</p>
										</TableCell>
										<TableCell>
											<p className="text-sm text-zinc-700 dark:text-zinc-300">
												{user.last_activity_at || "—"}
											</p>
										</TableCell>
										<TableCell>
											<div className="space-y-1">
												<p className="text-sm font-medium text-zinc-800 dark:text-zinc-100">
													{user.last_stage_label}
												</p>
												<Badge color={risk.color}>{risk.label}</Badge>
											</div>
										</TableCell>
										<TableCell>
											<span className="tabular-nums text-sm text-zinc-600 dark:text-zinc-300">
												{user.days_stalled}d
											</span>
										</TableCell>
										<TableCell>
											<div className="space-y-2">
												<ScoreBar value={user.lead_score} label="Score" />
												<ScoreBar value={user.ai_probability} label="IA" />
											</div>
										</TableCell>
										<TableCell>
											<div
												className="flex flex-col gap-1"
												onClick={(e) => e.stopPropagation()}
											>
												<Button plain onClick={() => onSelect?.(user)}>
													Timeline
												</Button>
												{user.show_url ? (
													<Button href={user.show_url} plain>
														Ficha
													</Button>
												) : null}
											</div>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</div>
			)}
			{users?.total > 0 ? (
				<div className="mt-4">
					<Pagination paginatedModels={users} />
				</div>
			) : null}
		</ChartCard>
	);
}
