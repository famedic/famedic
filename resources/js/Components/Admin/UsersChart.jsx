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

const SERIES_ACTIVITY = [
	{ key: "registrations", name: "Registros", color: "#009ad8" },
	{ key: "email_verified", name: "Correo verificado (ese día)", color: "#16a34a" },
	{ key: "phone_verified", name: "Teléfono verificado (ese día)", color: "#0d9488" },
];

const SERIES_ACCOUNTS = [
	{ key: "regular", name: "Cuenta regular", color: "#7c3aed" },
	{ key: "odessa", name: "Caja de ahorro (Odessa)", color: "#ea580c" },
	{ key: "family", name: "Cuenta familiar", color: "#db2777" },
	{ key: "certificate", name: "Certificado", color: "#64748b" },
	{
		key: "google_proxy",
		name: "Sin contraseña (aprox. Google)",
		color: "#ca8a04",
	},
];

export default function UsersChart({ chart }) {
	if (!chart?.dataPoints?.length) {
		return (
			<div className="rounded-xl border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-900">
				<Text>No hay datos para el rango seleccionado.</Text>
			</div>
		);
	}

	return (
		<div className="space-y-10">
			<div>
				<Subheading className="mb-2">Actividad y verificaciones</Subheading>
				<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
					Los conteos de correo y teléfono son usuarios que verificaron ese día
					(fecha de verificación), no necesariamente el mismo día del registro.
				</Text>
				<ResponsiveContainer height={320} className={lineClass}>
					<LineChart data={chart.dataPoints}>
						<CartesianGrid vertical={false} />
						<XAxis
							className="text-xs"
							tickLine={false}
							axisLine={false}
							dataKey="date"
						/>
						<YAxis
							width={48}
							allowDecimals={false}
							className="text-xs"
							tickLine={false}
							axisLine={false}
						/>
						<Tooltip
							content={(props) => (
								<UsersChartTooltip {...props} series={SERIES_ACTIVITY} />
							)}
						/>
						<Legend wrapperStyle={{ fontSize: "12px" }} />
						{SERIES_ACTIVITY.map(({ key, name, color }) => (
							<Line
								key={key}
								dot={false}
								type="monotone"
								name={name}
								dataKey={key}
								stroke={color}
								strokeWidth={2}
							/>
						))}
					</LineChart>
				</ResponsiveContainer>
			</div>

			<div>
				<Subheading className="mb-2">Tipo de cuenta al registrarse</Subheading>
				<Text className="mb-4 text-sm text-zinc-600 dark:text-zinc-400">
					Se cuenta el día de alta del usuario según el tipo de cliente vinculado.
					“Sin contraseña” es una aproximación a registro o acceso con Google (no
					implica que solo hayan usado Google si después definieron contraseña).
				</Text>
				<ResponsiveContainer height={360} className={lineClass}>
					<LineChart data={chart.dataPoints}>
						<CartesianGrid vertical={false} />
						<XAxis
							className="text-xs"
							tickLine={false}
							axisLine={false}
							dataKey="date"
						/>
						<YAxis
							width={48}
							allowDecimals={false}
							className="text-xs"
							tickLine={false}
							axisLine={false}
						/>
						<Tooltip
							content={(props) => (
								<UsersChartTooltip {...props} series={SERIES_ACCOUNTS} />
							)}
						/>
						<Legend wrapperStyle={{ fontSize: "12px" }} />
						{SERIES_ACCOUNTS.map(({ key, name, color }) => (
							<Line
								key={key}
								dot={false}
								type="monotone"
								name={name}
								dataKey={key}
								stroke={color}
								strokeWidth={2}
							/>
						))}
					</LineChart>
				</ResponsiveContainer>
			</div>
		</div>
	);
}

function UsersChartTooltip({ active, payload, label, series }) {
	if (!active || !payload?.length) {
		return null;
	}

	const map = Object.fromEntries(series.map((s) => [s.key, s.name]));

	return (
		<div className="rounded-lg bg-white shadow-lg ring-1 ring-slate-950/10 dark:bg-slate-900 dark:ring-white/10 max-w-xs">
			<div className="px-4 py-1">
				<Subheading>{label}</Subheading>
			</div>
			<Divider />
			<div className="space-y-1 px-4 py-2 text-sm">
				{payload
					.filter((p) => p.value != null && Number(p.value) > 0)
					.map((p) => (
						<div key={p.dataKey} className="flex justify-between gap-4">
							<span className="text-zinc-600 dark:text-zinc-400">
								{map[p.dataKey] ?? p.name}
							</span>
							<span className="font-medium tabular-nums">{p.value}</span>
						</div>
					))}
				{payload.every((p) => !p.value || Number(p.value) === 0) && (
					<Text className="text-zinc-500">Sin actividad este día</Text>
				)}
			</div>
		</div>
	);
}
