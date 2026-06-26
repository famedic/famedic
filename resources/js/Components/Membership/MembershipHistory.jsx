import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import clsx from "clsx";

const TYPE_STYLES = {
	purchase: {
		color: "violet",
		dot: "bg-violet-500",
	},
	payment: {
		color: "emerald",
		dot: "bg-emerald-500",
	},
	beneficiary: {
		color: "sky",
		dot: "bg-sky-500",
	},
	renewal: {
		color: "amber",
		dot: "bg-amber-500",
	},
	change: {
		color: "zinc",
		dot: "bg-zinc-400",
	},
};

export default function MembershipHistory({ timeline = [] }) {
	if (timeline.length === 0) {
		return (
			<Card className="rounded-2xl p-8 ring-1 ring-slate-100">
				<Text className="text-sm text-zinc-500">
					Aún no hay eventos registrados en tu membresía.
				</Text>
			</Card>
		);
	}

	return (
		<div className="space-y-6">
			<div>
				<h3 className="font-poppins text-lg font-semibold text-famedic-dark dark:text-white">
					Historial
				</h3>
				<Text className="text-sm text-zinc-500">
					Línea de tiempo de compras, pagos y cambios.
				</Text>
			</div>

			<Card className="rounded-2xl p-5 shadow-sm ring-1 ring-slate-100 sm:p-8">
				<ol className="relative space-y-0">
					{timeline.map((event, index) => {
						const style =
							TYPE_STYLES[event.type] ?? TYPE_STYLES.change;
						const isLast = index === timeline.length - 1;

						return (
							<li
								key={event.id}
								className="relative flex gap-4 pb-8 last:pb-0"
							>
								{!isLast && (
									<span
										className="absolute left-[11px] top-6 h-[calc(100%-12px)] w-px bg-slate-200 dark:bg-slate-700"
										aria-hidden="true"
									/>
								)}

								<div className="relative z-10 mt-1 flex size-6 shrink-0 items-center justify-center rounded-full bg-white ring-4 ring-white dark:bg-slate-900 dark:ring-slate-900">
									<span
										className={clsx(
											"size-2.5 rounded-full",
											style.dot,
										)}
									/>
								</div>

								<div className="min-w-0 flex-1 space-y-2">
									<div className="flex flex-wrap items-center gap-2">
										<p className="font-medium text-zinc-800 dark:text-slate-100">
											{event.title}
										</p>
										<Badge color={style.color}>
											{event.typeLabel}
										</Badge>
									</div>
									<Text className="text-sm text-zinc-500">
										{event.description}
									</Text>
									<div className="flex flex-wrap items-center gap-3 text-sm">
										<span className="text-zinc-400">
											{event.date}
										</span>
										{event.amount && (
											<span className="font-medium text-famedic-dark dark:text-white">
												{event.amount}
											</span>
										)}
									</div>
								</div>
							</li>
						);
					})}
				</ol>
			</Card>
		</div>
	);
}
