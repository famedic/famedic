import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ResponsiveContainer,
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
} from "recharts";
import { Divider } from "@/Components/Catalyst/divider";
import { useForm } from "@inertiajs/react";
import { useMemo } from "react";

function formatDiff(minutes) {
	if (minutes == null) return "—";
	if (minutes < 60) return `${minutes} min`;
	const h = Math.floor(minutes / 60);
	const m = minutes % 60;
	return `${h}h ${m}m`;
}

export default function LaboratoryNotificationsMonitor({ filters, dailyChart, orders }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date,
		end_date: filters.end_date,
	});

	const showUpdateButton = useMemo(
		() =>
			data.start_date !== filters.start_date ||
			data.end_date !== filters.end_date,
		[data, filters],
	);

	const update = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-notifications-monitor.index"), {
				preserveState: true,
			});
		}
	};

	return (
		<AdminLayout title="Monitor notificaciones laboratorio">
			<div className="space-y-6">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Monitor notificaciones de laboratorio</Heading>

					<form onSubmit={update} className="flex flex-wrap gap-2 items-end">
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500">Inicio</Text>
							<input
								type="date"
								value={data.start_date}
								onChange={(e) => setData("start_date", e.target.value)}
								className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
							/>
						</div>
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500">Fin</Text>
							<input
								type="date"
								value={data.end_date}
								onChange={(e) => setData("end_date", e.target.value)}
								className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
							/>
						</div>
						<Button type="submit" disabled={processing || !showUpdateButton}>
							Actualizar
						</Button>
					</form>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<div className="flex flex-wrap justify-end gap-x-4 gap-y-2">
						<div className="flex items-center gap-1">
							<Text>{dailyChart.averagePerDay}</Text>
							<Badge color="slate">promedio por día</Badge>
						</div>
						<div className="flex items-center gap-1">
							<Text>{dailyChart.total}</Text>
							<Badge color="slate">total</Badge>
						</div>
					</div>

					<ResponsiveContainer height={320} className="mt-4">
						<LineChart
							data={dailyChart.dataPoints}
							className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
						>
							<CartesianGrid vertical={false} />
							<XAxis tickLine={false} axisLine={false} dataKey="date" className="text-xs" />
							<YAxis tickLine={false} axisLine={false} className="text-xs" width={60} />
							<Tooltip content={<DailyTooltip />} cursor={{ strokeWidth: 1.5, strokeDasharray: "10 3" }} />
							<Line dot={false} type="monotone" dataKey="sample" stroke="#0ea5e9" strokeWidth={2} />
							<Line dot={false} type="monotone" dataKey="results" stroke="#22c55e" strokeWidth={2} />
						</LineChart>
					</ResponsiveContainer>

					<div className="mt-3 flex flex-wrap gap-2 text-xs text-zinc-500">
						<span className="inline-flex items-center gap-2">
							<span className="h-2 w-2 rounded-full bg-sky-500" /> Toma de muestra
						</span>
						<span className="inline-flex items-center gap-2">
							<span className="h-2 w-2 rounded-full bg-green-500" /> Resultados
						</span>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Órdenes (agrupado por número de orden)</Subheading>
					<Divider className="my-4" />

					<PaginatedTable paginatedData={orders}>
						<Table>
							<TableHead>
								<TableRow>
									<TableHeader>Orden</TableHeader>
									<TableHeader>Propietario</TableHeader>
									<TableHeader>Toma de muestra</TableHeader>
									<TableHeader>Resultados</TableHeader>
									<TableHeader>Tiempo</TableHeader>
									<TableHeader>Notifs</TableHeader>
									<TableHeader></TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{orders.data.map((o) => (
									<TableRow key={o.gda_order_id}>
										<TableCell>
											<Strong>{o.gda_order_id}</Strong>
										</TableCell>
										<TableCell>
											{o.owner ? (
												<div className="space-y-1">
													<Text className="text-sm">
														<Strong>{o.owner.full_name}</Strong>
													</Text>
													<Text className="text-xs text-zinc-500">{o.owner.email}</Text>
												</div>
											) : (
												<Text className="text-xs text-zinc-400">—</Text>
											)}
										</TableCell>
										<TableCell>
											<Text className="text-xs">{o.sample_at ? new Date(o.sample_at).toLocaleString("es-MX") : "—"}</Text>
										</TableCell>
										<TableCell>
											<Text className="text-xs">{o.results_at ? new Date(o.results_at).toLocaleString("es-MX") : "—"}</Text>
										</TableCell>
										<TableCell>
											<Badge color="slate">{formatDiff(o.diff_minutes)}</Badge>
										</TableCell>
										<TableCell>
											<div className="flex gap-2">
												<Badge color="sky">M: {o.sample_notifications}</Badge>
												<Badge color="emerald">R: {o.results_notifications}</Badge>
											</div>
										</TableCell>
										<TableCell>
											<Button
												outline
												size="sm"
												href={route("admin.laboratory-notifications-monitor.show", {
													gdaOrderId: o.gda_order_id,
												})}
											>
												Ver detalle
											</Button>
										</TableCell>
									</TableRow>
								))}
							</TableBody>
						</Table>
					</PaginatedTable>
				</div>
			</div>
		</AdminLayout>
	);
}

function DailyTooltip({ active, payload, label }) {
	if (!active || !payload || payload.length === 0) return null;
	const p = payload[0]?.payload;
	return (
		<div className="rounded-lg bg-white shadow-lg ring-1 ring-slate-950/10 dark:bg-slate-900 dark:ring-white/10">
			<div className="px-4 py-2">
				<Subheading>{label}</Subheading>
			</div>
			<Divider />
			<div className="px-4 py-2 space-y-1">
				<Text className="text-sm">Toma de muestra: <Strong>{p.sample}</Strong></Text>
				<Text className="text-sm">Resultados: <Strong>{p.results}</Strong></Text>
				<Text className="text-sm">Total: <Strong>{p.total}</Strong></Text>
			</div>
		</div>
	);
}

