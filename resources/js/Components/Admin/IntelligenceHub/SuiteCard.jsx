import { Link } from "@inertiajs/react";
import { ArrowRightIcon } from "@heroicons/react/24/outline";
import SuiteBadge from "./SuiteBadge";
import SuiteStats from "./SuiteStats";

const ACCENT = {
	indigo: "hover:border-indigo-200 hover:shadow-indigo-500/5 dark:hover:border-indigo-500/30",
	orange: "hover:border-orange-200 hover:shadow-orange-500/5 dark:hover:border-orange-500/30",
	purple: "hover:border-purple-200 hover:shadow-purple-500/5 dark:hover:border-purple-500/30",
	sky: "hover:border-sky-200 hover:shadow-sky-500/5 dark:hover:border-sky-500/30",
	violet: "hover:border-violet-200 hover:shadow-violet-500/5 dark:hover:border-violet-500/30",
	slate: "hover:border-zinc-300 dark:hover:border-zinc-600",
	green: "hover:border-emerald-200 hover:shadow-emerald-500/5 dark:hover:border-emerald-500/30",
};

export default function SuiteCard({ suite }) {
	const accent = ACCENT[suite.accent] || ACCENT.slate;

	return (
		<Link
			href={suite.href}
			className={`group flex h-full flex-col rounded-3xl border border-zinc-200/80 bg-white p-6 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg dark:border-zinc-800 dark:bg-zinc-900/70 ${accent}`}
		>
			<div className="flex items-start justify-between gap-3">
				<span className="text-3xl" aria-hidden="true">
					{suite.emoji}
				</span>
				<SuiteBadge variant="beta" className="ml-0">
					Suite
				</SuiteBadge>
			</div>

			<div className="mt-5 flex-1">
				<h3 className="text-xl font-semibold tracking-tight text-zinc-950 dark:text-white">
					{suite.name}
				</h3>
				<p className="mt-2 text-sm leading-relaxed text-zinc-500 dark:text-zinc-400">
					{suite.description}
				</p>
			</div>

			<div className="mt-5">
				<SuiteStats stats={suite.stats || []} />
			</div>

			<div className="mt-6 flex items-center justify-between border-t border-zinc-100 pt-4 dark:border-zinc-800">
				<span className="text-xs font-medium text-zinc-400">
					{suite.module_count} módulos
				</span>
				<span className="inline-flex items-center gap-1.5 rounded-lg bg-zinc-950 px-3 py-1.5 text-xs font-semibold text-white transition group-hover:bg-zinc-800 dark:bg-white dark:text-zinc-950 dark:group-hover:bg-zinc-200">
					Abrir Suite
					<ArrowRightIcon className="size-3.5 transition-transform duration-300 group-hover:translate-x-0.5" />
				</span>
			</div>
		</Link>
	);
}
