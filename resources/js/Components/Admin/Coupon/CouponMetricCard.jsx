export default function CouponMetricCard({ label, value, hint, tone = "default" }) {
	const toneClasses = {
		default: "border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-900/60",
		lime: "border-famedic-lime/30 bg-famedic-lime/10 dark:border-famedic-lime/20 dark:bg-famedic-lime/5",
		amber: "border-amber-200 bg-amber-50/80 dark:border-amber-900 dark:bg-amber-950/30",
		red: "border-red-200 bg-red-50/80 dark:border-red-900 dark:bg-red-950/30",
		sky: "border-sky-200 bg-sky-50/80 dark:border-sky-900 dark:bg-sky-950/30",
	};

	return (
		<div
			className={[
				"rounded-xl border p-4 shadow-sm",
				toneClasses[tone] ?? toneClasses.default,
			].join(" ")}
		>
			<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				{label}
			</p>
			<p className="mt-1 font-poppins text-2xl font-semibold text-zinc-950 dark:text-white">
				{value}
			</p>
			{hint ? (
				<p className="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{hint}</p>
			) : null}
		</div>
	);
}
