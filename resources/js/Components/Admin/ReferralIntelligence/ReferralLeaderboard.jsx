import { Avatar } from "@/Components/Catalyst/avatar";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import ReferralBadge from "./ReferralBadge";

const MEDALS = ["🥇", "🥈", "🥉"];

export default function ReferralLeaderboard({
	title = "Top Invitadores",
	description = "Ranking Top 10 del periodo.",
	items = [],
	onSelect,
	variant = "inviters",
}) {
	return (
		<ChartCard title={title} description={description}>
			{items.length === 0 ? (
				<p className="py-8 text-center text-sm text-zinc-400">Sin datos en el periodo.</p>
			) : (
				<ul className="space-y-2">
					{items.map((item, index) => {
						const medal = MEDALS[index] || null;
						const isCompany = variant === "companies" || variant === "partners";

						return (
							<li key={item.id || item.label || index}>
								<button
									type="button"
									onClick={() => !isCompany && onSelect?.(item)}
									className={`flex w-full items-center gap-3 rounded-xl border border-transparent px-2 py-2 text-left transition hover:border-zinc-200 hover:bg-zinc-50 dark:hover:border-zinc-700 dark:hover:bg-zinc-800/60 ${
										isCompany ? "cursor-default" : ""
									}`}
								>
									<span className="w-6 text-center text-sm">
										{medal || index + 1}
									</span>
									{!isCompany ? (
										<Avatar src={item.avatar} className="size-9" />
									) : (
										<span className="flex size-9 items-center justify-center rounded-full bg-zinc-100 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
											{(item.label || "?").slice(0, 2).toUpperCase()}
										</span>
									)}
									<div className="min-w-0 flex-1">
										<p className="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-50">
											{isCompany ? item.label : item.name}
										</p>
										<p className="truncate text-xs text-zinc-500">
											{isCompany
												? `${item.value} referidos`
												: `${item.referrals} referidos · ${item.conversion}% conv.`}
										</p>
									</div>
									{!isCompany && item.level ? (
										<ReferralBadge
											tone={item.level.key}
											label={item.level.label}
											medal={item.level.medal}
										/>
									) : null}
								</button>
							</li>
						);
					})}
				</ul>
			)}
		</ChartCard>
	);
}
