import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Divider } from "@/Components/Catalyst/divider";
import {
	LineChart,
	Line,
	XAxis,
	YAxis,
	Tooltip,
	ResponsiveContainer,
	CartesianGrid,
	Legend,
} from "recharts";

const lineClass =
	"[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-zinc-200 dark:[&_.recharts-cartesian-grid-horizontal_>_line]:stroke-slate-700 [&_.recharts-tooltip-cursor]:stroke-famedic-dark dark:[&_.recharts-tooltip-cursor]:stroke-white";

function fmtHours(value) {
	if (value == null) {
		return "—";
	}
	return `${Number(value).toLocaleString("es-MX", {
		minimumFractionDigits: 0,
		maximumFractionDigits: 1,
	})} h`;
}

function StatCard({ title, value, hint }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<Text className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				{title}
			</Text>
			<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100">
				{value}
			</p>
			{hint && (
				<Text className="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
					{hint}
				</Text>
			)}
		</div>
	);
}

export default function LaboratoryAppointmentsDashboard({ dashboard }) {
	if (!dashboard?.dataPoints?.length) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
				<Text>No hay datos para el rango seleccionado.</Text>
			</div>
		);
	}

	const { summary, tiempos } = dashboard;

	return (
		<div className="space-y-8">
			<div className="grid gap-4 sm:grid-cols-3">
				<StatCard
					title="Solicitudes (creadas en el período)"
					value={summary.solicitudes_en_periodo}
					hint="Por fecha de alta de la cita."
				/>
				<StatCard
					title="Confirmaciones (fecha de confirmación en el período)"
					value={summary.confirmaciones_en_periodo}
					hint="Citas con confirmación registrada ese día."
				/>
				<StatCard
					title="Solicitudes sin confirmar (creadas en el período)"
					value={summary.solicitudes_sin_confirmar_creadas_en_periodo}
					hint="Siguen sin confirmed_at dentro del rango analizado."
				/>
			</div>

			<div className="grid gap-4 lg:grid-cols-2">
				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading className="mb-1">Tiempo hasta confirmación</Subheading>
					<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
						Solo citas cuya fecha de confirmación cae en el período: diferencia
						entre creación y <code className="text-xs">confirmed_at</code>.
					</Text>
					<dl className="space-y-2 text-sm">
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Promedio</dt>
							<dd className="font-medium tabular-nums">
								{fmtHours(tiempos.horas_promedio_hasta_confirmacion)}
							</dd>
						</div>
						<Divider />
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Mediana</dt>
							<dd className="font-medium tabular-nums">
								{fmtHours(tiempos.horas_mediana_hasta_confirmacion)}
							</dd>
						</div>
					</dl>
				</div>
				<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Subheading className="mb-1">Solicitud → última actualización</Subheading>
					<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
						Citas creadas en el período: diferencia entre{" "}
						<code className="text-xs">created_at</code> y{" "}
						<code className="text-xs">updated_at</code> (cualquier edición).
					</Text>
					<dl className="space-y-2 text-sm">
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Promedio</dt>
							<dd className="font-medium tabular-nums">
								{fmtHours(
									tiempos.horas_promedio_solicitud_a_ultima_actualizacion,
								)}
							</dd>
						</div>
						<Divider />
						<div className="flex justify-between gap-4">
							<dt className="text-zinc-500">Mediana</dt>
							<dd className="font-medium tabular-nums">
								{fmtHours(
									tiempos.horas_mediana_solicitud_a_ultima_actualizacion,
								)}
							</dd>
						</div>
					</dl>
				</div>
			</div>

			<div>
				<Subheading className="mb-2">Solicitudes vs confirmaciones por día</Subheading>
				<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
					Solicitudes: día en que se creó la cita. Confirmaciones: día del{" "}
					<code className="text-xs">confirmed_at</code>. Zona horaria Monterrey.
				</Text>
				<ResponsiveContainer height={360} className={lineClass}>
					<LineChart data={dashboard.dataPoints}>
						<CartesianGrid vertical={false} />
						<XAxis
							className="text-xs"
							tickLine={false}
							axisLine={false}
							dataKey="date"
						/>
						<YAxis
							width={40}
							allowDecimals={false}
							className="text-xs"
							tickLine={false}
							axisLine={false}
						/>
						<Tooltip
							contentStyle={{
								borderRadius: "0.5rem",
								border: "1px solid rgb(228 228 231)",
							}}
						/>
						<Legend wrapperStyle={{ fontSize: "12px" }} />
						<Line
							dot={false}
							type="monotone"
							name="Solicitudes"
							dataKey="solicitudes"
							stroke="#009ad8"
							strokeWidth={2}
						/>
						<Line
							dot={false}
							type="monotone"
							name="Confirmaciones"
							dataKey="confirmaciones"
							stroke="#16a34a"
							strokeWidth={2}
						/>
					</LineChart>
				</ResponsiveContainer>
			</div>
		</div>
	);
}
