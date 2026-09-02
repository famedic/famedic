import Card from "@/Components/Card";
import { Strong } from "@/Components/Catalyst/text";
import {
	ChatBubbleLeftRightIcon,
	CalendarDaysIcon,
	CreditCardIcon,
} from "@heroicons/react/24/outline";
import clsx from "clsx";

const STEPS = [
	{ label: "Escríbenos", lines: ["Escríbenos"], Icon: ChatBubbleLeftRightIcon },
	{
		label: "Confirmamos tu cita",
		lines: ["Confirmamos", "tu cita"],
		Icon: CalendarDaysIcon,
	},
	{
		label: "Continúa con el pago",
		lines: ["Continúa", "con el pago"],
		Icon: CreditCardIcon,
	},
];

export default function AppointmentConfirmationSteps({ className }) {
	return (
		<Card
			className={clsx(
				"flex h-full flex-col p-5 sm:p-6",
				className,
			)}
		>
			<Strong className="text-sm font-semibold text-zinc-900 dark:text-white">
				Así confirmamos tu cita
			</Strong>

			<ol
				className="mt-4 flex flex-1 flex-col justify-center gap-4 sm:flex-row sm:items-stretch sm:gap-2"
				aria-label="Proceso de confirmación de cita"
			>
				{STEPS.map(({ label, lines, Icon }, index) => (
					<li
						key={label}
						className="relative flex min-w-0 flex-1 flex-col items-center text-center"
					>
						{index > 0 && (
							<span
								className="absolute -left-2 top-5 hidden h-px w-4 bg-zinc-200 sm:-left-1 sm:block sm:w-[calc(50%-1.75rem)] dark:bg-zinc-700"
								aria-hidden="true"
							/>
						)}
						{index < STEPS.length - 1 && (
							<span
								className="absolute -right-2 top-5 hidden h-px w-4 bg-zinc-200 sm:-right-1 sm:block sm:w-[calc(50%-1.75rem)] dark:bg-zinc-700"
								aria-hidden="true"
							/>
						)}

						<span className="flex size-10 items-center justify-center rounded-xl bg-emerald-50 text-teal-800 ring-1 ring-emerald-100 dark:bg-teal-950/40 dark:text-teal-200 dark:ring-teal-900/50">
							<Icon className="size-5" aria-hidden="true" />
						</span>

						<span className="mt-2 flex size-5 items-center justify-center rounded-full bg-zinc-900 text-[10px] font-bold text-white dark:bg-zinc-100 dark:text-zinc-900">
							{index + 1}
						</span>

						<span className="mt-1 text-xs font-medium leading-snug text-zinc-700 dark:text-zinc-300">
							<span className="sr-only">Paso {index + 1}:</span>
							{lines.map((line) => (
								<span key={line} className="block">
									{line}
								</span>
							))}
						</span>
					</li>
				))}
			</ol>
		</Card>
	);
}
