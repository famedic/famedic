import clsx from "clsx";
import { ChartCard, TONE_CLASSES } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function HealthGauge({ gauge, bands = [] }) {
	const average = gauge?.average ?? 0;
	const circumference = 2 * Math.PI * 54;
	const progress = Math.min(100, Math.max(0, average)) / 100;
	const offset = circumference * (1 - progress);

	return (
		<ChartCard
			title="Health score promedio"
			description={`Muestra analizada: ${Number(gauge?.sample_size || 0).toLocaleString()} clientes`}
		>
			<div className="flex flex-col items-center gap-6 lg:flex-row lg:items-start">
				<div className="relative flex size-44 items-center justify-center">
					<svg className="size-44 -rotate-90" viewBox="0 0 128 128">
						<circle
							cx="64"
							cy="64"
							r="54"
							fill="none"
							stroke="currentColor"
							strokeWidth="10"
							className="text-zinc-100 dark:text-zinc-800"
						/>
						<circle
							cx="64"
							cy="64"
							r="54"
							fill="none"
							stroke="currentColor"
							strokeWidth="10"
							strokeLinecap="round"
							strokeDasharray={circumference}
							strokeDashoffset={offset}
							className={clsx(
								average >= 81 && "text-emerald-500",
								average >= 61 && average < 81 && "text-sky-500",
								average >= 41 && average < 61 && "text-orange-500",
								average >= 21 && average < 41 && "text-rose-500",
								average < 21 && "text-zinc-400",
							)}
						/>
					</svg>
					<div className="absolute text-center">
						<p className="text-3xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{Number(average).toFixed(1)}
						</p>
						<p className="text-[11px] uppercase tracking-wide text-zinc-400">
							Promedio
						</p>
					</div>
				</div>
				<div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-1 xl:grid-cols-2">
					{bands.map((band) => {
						const bandTone = TONE_CLASSES[band.tone] || TONE_CLASSES.slate;
						return (
							<div
								key={band.key}
								className="rounded-xl border border-zinc-200 px-3 py-2 dark:border-zinc-700"
							>
								<div className="flex items-center gap-2">
									<span className={clsx("size-2.5 rounded-full", bandTone.bar)} />
									<p className="text-xs font-medium text-zinc-500">{band.label}</p>
								</div>
								<p className="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
									{Number(band.count || 0).toLocaleString()}
								</p>
							</div>
						);
					})}
				</div>
			</div>
		</ChartCard>
	);
}
