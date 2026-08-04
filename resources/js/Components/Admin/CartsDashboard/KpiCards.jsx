import clsx from "clsx";
import {
	ResponsiveContainer,
	AreaChart,
	Area,
} from "recharts";
import {
	ArrowTrendingDownIcon,
	ArrowTrendingUpIcon,
	MinusIcon,
	ShoppingCartIcon,
	XCircleIcon,
	ArrowPathIcon,
	CheckCircleIcon,
	BanknotesIcon,
	CurrencyDollarIcon,
	ChartBarIcon,
	ReceiptPercentIcon,
} from "@heroicons/react/16/solid";
import { Text } from "@/Components/Catalyst/text";
import { DASHBOARD_COLORS, TONE_CLASSES } from "./chartTheme.jsx";

const ICONS = {
	created: ShoppingCartIcon,
	abandoned: XCircleIcon,
	recovered: ArrowPathIcon,
	sales: CheckCircleIcon,
	lost_value: BanknotesIcon,
	recovered_value: CurrencyDollarIcon,
	conversion: ChartBarIcon,
	avg_ticket: ReceiptPercentIcon,
};

function DeltaBadge({ kpi }) {
	if (kpi.delta_percent === null || kpi.delta_percent === undefined) {
		return (
			<span className="inline-flex items-center gap-1 text-xs text-zinc-400">
				<MinusIcon className="size-3.5" />
				Sin base
			</span>
		);
	}

	const Icon =
		kpi.delta_direction === "up"
			? ArrowTrendingUpIcon
			: kpi.delta_direction === "down"
				? ArrowTrendingDownIcon
				: MinusIcon;

	return (
		<span
			className={clsx(
				"inline-flex items-center gap-1 text-xs font-medium",
				kpi.delta_is_positive === true && "text-emerald-600 dark:text-emerald-400",
				kpi.delta_is_positive === false && "text-rose-600 dark:text-rose-400",
				kpi.delta_is_positive === null && "text-zinc-500",
			)}
		>
			<Icon className="size-3.5" />
			{kpi.delta_direction === "flat"
				? "0%"
				: `${kpi.delta_direction === "down" ? "−" : "+"}${kpi.delta_percent}%`}
		</span>
	);
}

function Sparkline({ data, color }) {
	if (!data?.length) {
		return <div className="h-10" />;
	}

	return (
		<div className="h-10 w-full">
			<ResponsiveContainer width="100%" height="100%">
				<AreaChart data={data} margin={{ top: 4, right: 0, left: 0, bottom: 0 }}>
					<Area
						type="monotone"
						dataKey="value"
						stroke={color}
						fill={color}
						fillOpacity={0.15}
						strokeWidth={1.5}
						isAnimationActive={false}
						dot={false}
					/>
				</AreaChart>
			</ResponsiveContainer>
		</div>
	);
}

export default function KpiCards({ kpis = [] }) {
	return (
		<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
			{kpis.map((kpi) => {
				const Icon = ICONS[kpi.id] || ShoppingCartIcon;
				const tone = TONE_CLASSES[kpi.tone] || TONE_CLASSES.blue;
				const color = DASHBOARD_COLORS[kpi.tone] || DASHBOARD_COLORS.blue;

				return (
					<div
						key={kpi.id}
						className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
					>
						<div className="flex items-start justify-between gap-3">
							<div
								className={clsx(
									"flex size-9 items-center justify-center rounded-lg",
									tone.icon,
								)}
							>
								<Icon className="size-4" />
							</div>
							<DeltaBadge kpi={kpi} />
						</div>
						<p className="mt-3 text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
							{kpi.label}
						</p>
						<p className="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50">
							{kpi.value_formatted}
						</p>
						{kpi.hint ? (
							<Text className="mt-1 text-[11px] text-zinc-400">
								{kpi.hint}
							</Text>
						) : null}
						<div className="mt-3">
							<Sparkline data={kpi.sparkline} color={color} />
						</div>
						<p className="mt-1 text-[11px] text-zinc-400">
							Periodo anterior: {kpi.previous_formatted}
						</p>
					</div>
				);
			})}
		</div>
	);
}
