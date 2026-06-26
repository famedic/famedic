import Card from "@/Components/Card";
import { Text } from "@/Components/Catalyst/text";

export default function MembershipProgress({ progress }) {
	if (!progress) {
		return null;
	}

	const percentageRemaining = Math.max(
		0,
		Math.min(100, 100 - progress.percentageUsed),
	);
	const circumference = 2 * Math.PI * 42;
	const strokeDashoffset =
		circumference - (percentageRemaining / 100) * circumference;

	return (
		<Card className="p-6 shadow-sm ring-1 ring-slate-100 sm:p-8">
			<div className="flex flex-col items-center gap-8 sm:flex-row sm:items-center sm:justify-between">
				<div className="relative flex size-36 items-center justify-center">
					<svg
						className="size-full -rotate-90"
						viewBox="0 0 100 100"
						aria-hidden="true"
					>
						<circle
							cx="50"
							cy="50"
							r="42"
							fill="none"
							stroke="currentColor"
							strokeWidth="8"
							className="text-slate-100 dark:text-slate-800"
						/>
						<circle
							cx="50"
							cy="50"
							r="42"
							fill="none"
							stroke="currentColor"
							strokeWidth="8"
							strokeLinecap="round"
							strokeDasharray={circumference}
							strokeDashoffset={strokeDashoffset}
							className="text-famedic-dark transition-all duration-500 dark:text-sky-400"
						/>
					</svg>
					<div className="absolute inset-0 flex flex-col items-center justify-center text-center">
						<span className="font-poppins text-3xl font-bold text-famedic-dark dark:text-white">
							{progress.remainingDays}
						</span>
						<Text className="text-xs text-zinc-500">
							días restantes
						</Text>
					</div>
				</div>

				<div className="w-full flex-1 space-y-4">
					<div className="flex items-end justify-between gap-4">
						<div>
							<Text className="text-sm text-zinc-500">
								Periodo total
							</Text>
							<p className="font-poppins text-xl font-semibold text-famedic-dark dark:text-white">
								{progress.totalDays} días
							</p>
						</div>
						<div className="text-right">
							<Text className="text-sm text-zinc-500">
								Utilizados
							</Text>
							<p className="font-poppins text-xl font-semibold text-zinc-700 dark:text-slate-200">
								{progress.usedDays} días
							</p>
						</div>
					</div>

					<div className="space-y-2">
						<div className="h-2.5 overflow-hidden rounded-full bg-slate-100 dark:bg-slate-800">
							<div
								className="h-full rounded-full bg-gradient-to-r from-famedic-dark to-violet-500 transition-all duration-500"
								style={{ width: `${progress.percentageUsed}%` }}
							/>
						</div>
						<div className="flex justify-between text-xs text-zinc-500">
							<span>{progress.usedDays} días utilizados</span>
							<span>{progress.remainingDays} días restantes</span>
						</div>
					</div>
				</div>
			</div>
		</Card>
	);
}
