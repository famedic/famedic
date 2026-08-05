import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyTimeline({ timeline = [] }) {
	const maxDay = Math.max(...timeline.map((t) => t.day || 0), 1);

	return (
		<ChartCard
			title="Journey timeline (promedio)"
			description="Días promedio desde el registro hasta cada hito."
		>
			<div className="relative space-y-0 pl-2">
				<div className="absolute bottom-3 left-[19px] top-3 w-px bg-zinc-200 dark:bg-zinc-700" />
				{timeline.map((item, index) => (
					<div key={item.key || index} className="relative flex gap-4 py-3">
						<div className="relative z-10 flex size-10 shrink-0 items-center justify-center rounded-full border-2 border-sky-500 bg-white text-xs font-semibold text-sky-700 dark:bg-zinc-900 dark:text-sky-300">
							D{Math.round(item.day)}
						</div>
						<div className="min-w-0 flex-1 rounded-xl border border-zinc-200 bg-zinc-50/80 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800/40">
							<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								{item.label}
							</p>
							<p className="text-xs text-zinc-500">
								Día {Number(item.day).toFixed(1)} ·{" "}
								{Math.round((item.day / maxDay) * 100)}% del camino a compra
							</p>
							<div className="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
								<div
									className="h-full rounded-full bg-sky-500 transition-all duration-500"
									style={{ width: `${Math.max(8, (item.day / maxDay) * 100)}%` }}
								/>
							</div>
						</div>
					</div>
				))}
			</div>
		</ChartCard>
	);
}
