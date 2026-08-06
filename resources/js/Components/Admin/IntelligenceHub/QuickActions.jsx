import { Link } from "@inertiajs/react";
import { BoltIcon } from "@heroicons/react/16/solid";
import SectionTitle from "./SectionTitle";

export default function QuickActions({ actions = [] }) {
	if (!actions.length) {
		return null;
	}

	return (
		<section>
			<SectionTitle
				eyebrow="Atajos"
				title="Quick Actions"
				description="Accesos directos a los módulos más usados."
			/>
			<div className="flex flex-wrap gap-2">
				{actions.map((action) => (
					<Link
						key={action.id}
						href={action.href}
						className="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-4 py-2 text-sm font-medium text-zinc-700 transition hover:-translate-y-0.5 hover:border-zinc-300 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600"
					>
						<BoltIcon className="size-4 text-amber-500" />
						{action.label}
					</Link>
				))}
			</div>
		</section>
	);
}
