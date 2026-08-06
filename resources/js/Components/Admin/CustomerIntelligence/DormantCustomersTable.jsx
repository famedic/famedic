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

function ScoreBar({ value, tone = "sky" }) {
	const color =
		value >= 70 ? "bg-emerald-500" : value >= 40 ? "bg-amber-500" : "bg-rose-500";

	return (
		<div className="min-w-[88px]">
			<div className="mb-1 flex justify-between text-[10px] tabular-nums text-zinc-500">
				<span>{tone === "ai" ? "IA" : "Score"}</span>
				<span>{value}%</span>
			</div>
			<div className="h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
				<div className={`h-full rounded-full ${color}`} style={{ width: `${value}%` }} />
			</div>
		</div>
	);
}

export default function DormantCustomersTable({ customers, onSelect }) {
	const rows = customers?.data || [];

	return (
		<ChartCard
			title="Clientes dormidos"
			description="Listado accionable. Clic en una fila para abrir el perfil 360."
		>
			{rows.length === 0 ? (
				<div className="flex h-40 items-center justify-center text-sm text-zinc-400">
					No hay clientes dormidos con los filtros actuales.
				</div>
			) : (
				<div className="overflow-x-auto">
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Cliente</TableHeader>
								<TableHeader>Ubicación</TableHeader>
								<TableHeader>Registro</TableHeader>
								<TableHeader>Actividad</TableHeader>
								<TableHeader>Engagement</TableHeader>
								<TableHeader>Score</TableHeader>
								<TableHeader>Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{rows.map((customer) => (
								<TableRow
									key={customer.id}
									className="cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50"
									onClick={() => onSelect?.(customer)}
								>
									<TableCell>
										<div className="flex items-center gap-3">
											<Avatar
												src={customer.avatar}
												initials={(customer.name || "?")
													.split(" ")
													.slice(0, 2)
													.map((p) => p[0])
													.join("")
													.toUpperCase()}
												className="size-9"
											/>
											<div className="min-w-0">
												<p className="truncate text-sm font-medium text-zinc-900 dark:text-zinc-50">
													{customer.name}
												</p>
												<p className="truncate text-xs text-zinc-500">
													{customer.email || "Sin email"}
												</p>
												<p className="truncate text-xs text-zinc-400">
													{customer.phone || "Sin teléfono"}
												</p>
											</div>
										</div>
									</TableCell>
									<TableCell>
										<p className="text-sm text-zinc-700 dark:text-zinc-300">
											{customer.city || "—"}
										</p>
										<p className="text-xs text-zinc-400">
											{customer.state || "Sin estado"}
										</p>
									</TableCell>
									<TableCell>
										<p className="text-sm text-zinc-700 dark:text-zinc-300">
											{customer.registered_at}
										</p>
										<div className="mt-1 flex flex-wrap gap-1">
											<Badge color="orange">
												{customer.days_without_purchase}d sin compra
											</Badge>
											<Badge color="zinc">
												{customer.registration_source}
											</Badge>
										</div>
									</TableCell>
									<TableCell>
										<p className="text-xs text-zinc-500">Última actividad</p>
										<p className="text-sm text-zinc-700 dark:text-zinc-300">
											{customer.last_activity_at || "—"}
										</p>
									</TableCell>
									<TableCell>
										<div className="space-y-1 text-xs text-zinc-500">
											<p>Lab cart: {customer.laboratory_cart_items_count}</p>
											<p>Farmacia: {customer.pharmacy_cart_items_count}</p>
											<p>Checkouts: {customer.checkout_attempts}</p>
											<p>Carritos abd.: {customer.abandoned_carts}</p>
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-2">
											<ScoreBar value={customer.lead_score} />
											<ScoreBar value={customer.ai_probability} tone="ai" />
										</div>
									</TableCell>
									<TableCell>
										<div
											className="flex flex-col gap-1"
											onClick={(e) => e.stopPropagation()}
										>
											<Button plain onClick={() => onSelect?.(customer)}>
												Ver 360
											</Button>
											{customer.show_url ? (
												<Button href={customer.show_url} plain>
													Ficha
												</Button>
											) : null}
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
