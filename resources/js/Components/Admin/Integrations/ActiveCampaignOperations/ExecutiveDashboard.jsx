import clsx from "clsx";
import DataSourceBadge from "@/Components/Common/DataSourceBadge";
import SectionHeader from "./SectionHeader";
import { provenanceForKpi, provenanceForSection } from "./provenanceCatalog";

function Sparkline({ values = [], tone = "sky" }) {
	if (!values.length) {
		return <div className="h-8 w-full rounded bg-zinc-100 dark:bg-zinc-800" />;
	}
	const max = Math.max(...values, 1);
	const min = Math.min(...values, 0);
	const span = Math.max(max - min, 1);
	const w = 80;
	const h = 28;
	const points = values
		.map((v, i) => {
			const x = (i / Math.max(values.length - 1, 1)) * w;
			const y = h - ((v - min) / span) * (h - 4) - 2;
			return `${x},${y}`;
		})
		.join(" ");

	const stroke =
		tone === "emerald"
			? "#059669"
			: tone === "rose"
				? "#e11d48"
				: tone === "amber"
					? "#d97706"
					: "#0284c7";

	return (
		<svg viewBox={`0 0 ${w} ${h}`} className="h-8 w-20" aria-hidden>
			<polyline
				fill="none"
				stroke={stroke}
				strokeWidth="2"
				strokeLinejoin="round"
				strokeLinecap="round"
				points={points}
			/>
		</svg>
	);
}

export default function ExecutiveKpiCard({ kpi, updatedAt = null }) {
	const provenance = provenanceForKpi(kpi.key);
	const trendColor =
		kpi.trend === "up"
			? "text-emerald-600"
			: kpi.trend === "down"
				? "text-rose-600"
				: "text-zinc-400";

	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-zinc-300 hover:shadow-md dark:border-zinc-700 dark:bg-zinc-900 dark:hover:border-zinc-600">
			<div className="flex items-start justify-between gap-2">
				<p className="text-[11px] font-medium uppercase tracking-wide text-zinc-500">
					{kpi.label}
				</p>
				<Sparkline values={kpi.sparkline || []} tone={kpi.tone} />
			</div>
			<p className="mt-2 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
				{kpi.value}
			</p>
			<div className="mt-1 flex items-center gap-2 text-[11px]">
				<span className={clsx("font-semibold tabular-nums", trendColor)}>
					{kpi.growth_percent == null
						? "—"
						: `${kpi.growth_percent > 0 ? "+" : ""}${kpi.growth_percent}%`}
				</span>
				<span className="text-zinc-400">vs ayer / periodo ant.</span>
			</div>
			<div className="mt-3">
				<DataSourceBadge
					source={provenance.source}
					mode={provenance.mode}
					quality={provenance.quality}
					ttl={provenance.ttl}
					endpoint={provenance.endpoint}
					updatedAt={updatedAt}
				/>
			</div>
			{kpi.hint ? (
				<p className="mt-2 text-[10px] leading-snug text-zinc-400">{kpi.hint}</p>
			) : null}
		</div>
	);
}

export function ExecutiveDashboard({ executive = [], updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Executive Dashboard"
				description="KPIs de alto nivel con tendencia y sparkline."
				provenance={provenanceForSection("executive")}
				updatedAt={updatedAt}
			/>
			<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
				{executive.map((kpi) => (
					<ExecutiveKpiCard key={kpi.key} kpi={kpi} updatedAt={updatedAt} />
				))}
			</div>
		</section>
	);
}
