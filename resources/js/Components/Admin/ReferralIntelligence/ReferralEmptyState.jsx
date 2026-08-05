import { UserGroupIcon } from "@heroicons/react/16/solid";
import { Text } from "@/Components/Catalyst/text";

export default function ReferralEmptyState({
	title = "Sin invitadores en este periodo",
	description = "Ajusta el rango de fechas o los filtros para ver actividad del programa de referidos.",
}) {
	return (
		<div className="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-zinc-50/60 px-6 py-16 text-center dark:border-zinc-700 dark:bg-zinc-900/40">
			<span className="flex size-12 items-center justify-center rounded-2xl bg-white shadow-sm dark:bg-zinc-800">
				<UserGroupIcon className="size-6 text-zinc-400" />
			</span>
			<p className="mt-4 text-sm font-semibold text-zinc-900 dark:text-zinc-50">
				{title}
			</p>
			<Text className="mt-1 max-w-md text-sm text-zinc-500">{description}</Text>
		</div>
	);
}
