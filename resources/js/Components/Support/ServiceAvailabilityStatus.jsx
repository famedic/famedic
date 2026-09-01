import { Text } from "@/Components/Catalyst/text";
import clsx from "clsx";

export default function ServiceAvailabilityStatus({
	isAvailable,
	availableMessage,
	afterHoursMessage,
}) {
	return (
		<div className="space-y-2" role="status" aria-live="polite">
			{isAvailable ? (
				<>
					<div
						className={clsx(
							"inline-flex items-center gap-2 rounded-full px-3 py-1.5",
							"bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200",
						)}
					>
						<span className="relative flex size-2.5" aria-hidden="true">
							<span className="absolute inline-flex size-full animate-ping rounded-full bg-emerald-500 opacity-60 motion-reduce:animate-none" />
							<span className="relative inline-flex size-2.5 rounded-full bg-emerald-600 dark:bg-emerald-400" />
						</span>
						<span className="text-sm font-semibold">
							En línea · Disponible ahora
						</span>
					</div>
					{availableMessage && (
						<Text className="text-sm text-emerald-800 dark:text-emerald-200">
							{availableMessage}
						</Text>
					)}
				</>
			) : (
				<>
					<div
						className={clsx(
							"inline-flex items-center gap-2 rounded-full px-3 py-1.5",
							"bg-amber-100 text-amber-900 dark:bg-amber-950/40 dark:text-amber-200",
						)}
					>
						<span
							className="size-2.5 rounded-full bg-amber-500 dark:bg-amber-400"
							aria-hidden="true"
						/>
						<span className="text-sm font-semibold">Fuera de horario</span>
					</div>
					{afterHoursMessage && (
						<Text className="text-sm text-zinc-600 dark:text-zinc-300">
							{afterHoursMessage}
						</Text>
					)}
				</>
			)}
		</div>
	);
}
