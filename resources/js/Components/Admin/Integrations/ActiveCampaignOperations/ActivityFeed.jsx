import {
	BoltIcon,
	ExclamationTriangleIcon,
	CheckCircleIcon,
	ShoppingCartIcon,
	TagIcon,
	UserIcon,
	SparklesIcon,
	HeartIcon,
} from "@heroicons/react/16/solid";
import SectionHeader from "./SectionHeader";
import StatusBadge from "./StatusBadge";
import { provenanceForSection } from "./provenanceCatalog";

const ICONS = {
	error: ExclamationTriangleIcon,
	check: CheckCircleIcon,
	cart: ShoppingCartIcon,
	tag: TagIcon,
	user: UserIcon,
	bolt: BoltIcon,
	membership: HeartIcon,
	activity: SparklesIcon,
};

export default function ActivityFeed({ activity = [], updatedAt = null }) {
	return (
		<section className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-5 dark:border-zinc-700 dark:bg-zinc-950/40">
			<SectionHeader
				title="Activity Feed"
				description="Timeline operacional (dispatches + diagnósticos). Listo para polling futuro."
				provenance={provenanceForSection("activity")}
				updatedAt={updatedAt}
			/>
			{activity.length === 0 ? (
				<p className="text-sm text-zinc-500">Sin actividad reciente.</p>
			) : (
				<ul className="space-y-2">
					{activity.map((row) => {
						const Icon = ICONS[row.icon] || SparklesIcon;
						return (
							<li
								key={row.id}
								className="flex gap-3 rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900"
							>
								<span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
									<Icon className="size-4" />
								</span>
								<div className="min-w-0 flex-1 space-y-1">
									<div className="flex flex-wrap items-center justify-between gap-2">
										<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
											{row.type_label}
										</p>
										<div className="flex items-center gap-2">
											<StatusBadge status={row.status} label={row.status_label} />
											<span className="text-[11px] tabular-nums text-zinc-400">
												{row.at}
											</span>
										</div>
									</div>
									<p className="text-xs text-zinc-500 dark:text-zinc-400">
										{row.description}
									</p>
								</div>
							</li>
						);
					})}
				</ul>
			)}
		</section>
	);
}
