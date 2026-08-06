import clsx from "clsx";
import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import DataStatusBadge from "@/Components/Common/DataStatusBadge";
import { detectDataStatus } from "@/Components/Common/dataProvenanceConstants";

const TONE = {
	default: "text-zinc-900 dark:text-zinc-50",
	emerald: "text-emerald-700 dark:text-emerald-400",
	amber: "text-amber-700 dark:text-amber-400",
	rose: "text-rose-700 dark:text-rose-400",
	sky: "text-sky-700 dark:text-sky-400",
};

export default function MetricCard({
	label,
	value,
	hint = null,
	tone = "default",
	loading = false,
	provenance = null,
	updatedAt = null,
}) {
	const status = detectDataStatus(value);

	return (
		<div className="min-w-0 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
			<div className="flex items-start justify-between gap-2">
				<p className="truncate text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					{label}
				</p>
				{provenance ? (
					<DataSourceBadge
						compact
						source={provenance.source}
						mode={provenance.mode}
						quality={provenance.quality}
						ttl={provenance.ttl}
						endpoint={provenance.endpoint}
						updatedAt={updatedAt || provenance.updatedAt}
						showMode={false}
					/>
				) : null}
			</div>
			{loading ? (
				<div className="mt-2 h-8 w-20 animate-pulse rounded bg-zinc-100 dark:bg-zinc-800" />
			) : status ? (
				<div className="mt-2">
					<DataStatusBadge status={status} detail={String(value)} />
				</div>
			) : (
				<p
					className={clsx(
						"mt-1 text-2xl font-semibold tabular-nums tracking-tight",
						TONE[tone] || TONE.default,
					)}
				>
					{value == null || value === "" ? "—" : value}
				</p>
			)}
			{hint ? (
				<p className="mt-1 text-[11px] leading-snug text-zinc-400">{hint}</p>
			) : null}
		</div>
	);
}
