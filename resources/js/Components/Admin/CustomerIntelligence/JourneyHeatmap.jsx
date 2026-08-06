import { useMemo } from "react";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

const HOURS = Array.from({ length: 24 }, (_, i) => i);
const DOWS = ["Dom", "Lun", "Mar", "Mié", "Jue", "Vie", "Sáb"];

function intensityColor(value, max) {
	if (!max || value <= 0) return "rgba(148, 163, 184, 0.12)";
	const t = value / max;
	const alpha = 0.15 + t * 0.85;
	return `rgba(37, 99, 235, ${alpha.toFixed(2)})`;
}

export default function JourneyHeatmap({ data = [], metricLabel = "Eventos" }) {
	const max = useMemo(
		() => Math.max(...data.map((cell) => cell.value || 0), 0),
		[data],
	);

	const lookup = useMemo(() => {
		const map = new Map();
		data.forEach((cell) => map.set(`${cell.dow}-${cell.hour}`, cell.value || 0));
		return map;
	}, [data]);

	return (
		<ChartCard
			title={`Heatmap · ${metricLabel}`}
			description="Día de la semana vs hora (America/Monterrey)."
		>
			<div className="overflow-x-auto">
				<div className="min-w-[640px]">
					<div
						className="grid gap-1"
						style={{ gridTemplateColumns: `48px repeat(24, minmax(18px, 1fr))` }}
					>
						<div />
						{HOURS.map((hour) => (
							<div
								key={hour}
								className="text-center text-[9px] text-zinc-400"
							>
								{hour}
							</div>
						))}
						{DOWS.map((label, dow) => (
							<div key={label} className="contents">
								<div className="flex items-center text-[11px] font-medium text-zinc-500">
									{label}
								</div>
								{HOURS.map((hour) => {
									const value = lookup.get(`${dow}-${hour}`) || 0;
									return (
										<div
											key={`${dow}-${hour}`}
											title={`${label} ${hour}:00 · ${value} ${metricLabel.toLowerCase()}`}
											className="aspect-square rounded-sm transition hover:ring-2 hover:ring-sky-400"
											style={{ background: intensityColor(value, max) }}
										/>
									);
								})}
							</div>
						))}
					</div>
					<div className="mt-3 flex items-center gap-2 text-[11px] text-zinc-500">
						<span>Menos</span>
						<div className="flex gap-0.5">
							{[0.15, 0.35, 0.55, 0.75, 1].map((t) => (
								<span
									key={t}
									className="size-3 rounded-sm"
									style={{
										background: `rgba(37, 99, 235, ${t})`,
									}}
								/>
							))}
						</div>
						<span>Más</span>
						<span className="ml-auto tabular-nums">
							Máx: {max.toLocaleString()}
						</span>
					</div>
				</div>
			</div>
		</ChartCard>
	);
}
