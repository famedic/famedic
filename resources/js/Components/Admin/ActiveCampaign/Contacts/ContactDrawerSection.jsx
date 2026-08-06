import { Text } from "@/Components/Catalyst/text";
import ContactTruthBadge from "./ContactTruthBadge";

/**
 * Contenedor de sección del Drawer 360 (placeholder visual).
 */
export default function ContactDrawerSection({
	title,
	description,
	truth,
	children = null,
}) {
	return (
		<section className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
			<div className="flex flex-wrap items-start justify-between gap-2">
				<div className="min-w-0 space-y-1">
					<h3 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						{title}
					</h3>
					{description ? (
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							{description}
						</Text>
					) : null}
				</div>
				<ContactTruthBadge truth={truth} />
			</div>

			<div className="mt-4">
				{children ?? (
					<div className="space-y-2" aria-hidden="true">
						<div className="h-3 w-3/4 max-w-[16rem] rounded bg-zinc-100 dark:bg-zinc-800" />
						<div className="h-3 w-full rounded bg-zinc-100 dark:bg-zinc-800" />
						<div className="h-3 w-2/3 max-w-[12rem] rounded bg-zinc-100 dark:bg-zinc-800" />
					</div>
				)}
			</div>
		</section>
	);
}
