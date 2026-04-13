import { useMemo } from "react";
import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	ResponsiveContainer,
	BarChart,
	Bar,
	XAxis,
	YAxis,
	Tooltip,
	CartesianGrid,
	Legend,
} from "recharts";

export default function LaboratoryAppointmentMetrics({
	filters,
	summary,
	requestedVsConfirmed,
	byBrand,
}) {
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
			get(route("admin.laboratory-appointments.metrics"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	return (
		<AdminLayout title="Métricas de citas de laboratorio">
			<div className="space-y-8">
				<div className="flex flex-wrap items-center justify-between gap-4">
					<Heading>Métricas de citas</Heading>
					<form
						onSubmit={update}
						className="flex flex-wrap items-end gap-2"
					>
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500">Inicio</Text>
							<input
								type="date"
								value={data.start_date}
								onChange={(e) =>
									setData("start_date", e.target.value)
								}
								className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
							/>
						</div>
						<div className="space-y-1">
							<Text className="text-xs text-zinc-500">Fin</Text>
							<input
								type="date"
								value={data.end_date}
								onChange={(e) =>
									setData("end_date", e.target.value)
								}
								className="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-800"
							/>
						</div>
						<Button
							type="submit"
							disabled={processing || !showUpdateButton}
						>
							Actualizar
						</Button>
					</form>
				</div>

				<div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Solicitudes en el periodo
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.total_solicitudes}
						</Text>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Confirmadas en el periodo
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.total_confirmadas}
						</Text>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Promedio horas (solicitud → confirmación)
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.promedio_horas_solicitud_a_confirmacion != null
								? `${summary.promedio_horas_solicitud_a_confirmacion} h`
								: "—"}
						</Text>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Pacientes con intento de llamada
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.pacientes_con_intento_llamada}
						</Text>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Dejaron disponibilidad o comentario
						</Text>
						<Text className="mt-1 text-2xl font-semibold">
							{summary.pacientes_con_disponibilidad_o_comentario}
						</Text>
					</div>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Solicitudes vs confirmadas por mes</Subheading>
					<Text className="mt-1 text-sm text-zinc-500">
						Citas creadas en el periodo (eje X = mes de la solicitud).
					</Text>
					<ResponsiveContainer height={320} className="mt-4">
						<BarChart
							data={requestedVsConfirmed}
							className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
						>
							<CartesianGrid vertical={false} />
							<XAxis
								dataKey="label"
								tickLine={false}
								axisLine={false}
								className="text-xs"
							/>
							<YAxis
								tickLine={false}
								axisLine={false}
								className="text-xs"
								width={48}
							/>
							<Tooltip
								cursor={{
									strokeWidth: 1.5,
									strokeDasharray: "6 3",
								}}
							/>
							<Legend />
							<Bar
								dataKey="solicitudes"
								name="Solicitudes"
								fill="#0ea5e9"
								radius={[4, 4, 0, 0]}
							/>
							<Bar
								dataKey="confirmadas"
								name="Confirmadas"
								fill="#22c55e"
								radius={[4, 4, 0, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				</div>

				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading>Citas por marca de laboratorio</Subheading>
					<Text className="mt-1 text-sm text-zinc-500">
						Total de solicitudes en el periodo y cuántas quedaron
						confirmadas.
					</Text>
					<ResponsiveContainer height={280} className="mt-4">
						<BarChart
							layout="vertical"
							data={byBrand}
							margin={{ left: 8, right: 16 }}
							className="[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white"
						>
							<CartesianGrid horizontal={false} />
							<XAxis type="number" hide />
							<YAxis
								type="category"
								dataKey="label"
								width={88}
								tickLine={false}
								axisLine={false}
								className="text-xs"
							/>
							<Tooltip cursor={{ fill: "rgba(148, 163, 184, 0.12)" }} />
							<Legend />
							<Bar
								dataKey="total"
								name="Solicitudes"
								fill="#6366f1"
								radius={[0, 4, 4, 0]}
							/>
							<Bar
								dataKey="confirmadas"
								name="Confirmadas"
								fill="#10b981"
								radius={[0, 4, 4, 0]}
							/>
						</BarChart>
					</ResponsiveContainer>
				</div>

				<div className="rounded-xl border border-amber-200 bg-amber-50/80 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
					<Text className="text-sm text-amber-950 dark:text-amber-100">
						<Strong>Otras métricas útiles:</Strong> tasa de conversión
						(confirmadas / solicitudes), tiempo mediano (menos sensible
						a valores extremos), cohortes por semana, o retraso entre
						intento de llamada del paciente y confirmación. Si quieres,
						podemos añadirlas en una siguiente iteración.
					</Text>
				</div>
			</div>
		</AdminLayout>
	);
}
