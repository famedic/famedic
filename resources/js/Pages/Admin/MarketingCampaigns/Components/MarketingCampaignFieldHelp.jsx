import { useId, useState } from "react";
import { QuestionMarkCircleIcon } from "@heroicons/react/20/solid";

export default function MarketingCampaignFieldHelp({ label, children }) {
	const [open, setOpen] = useState(false);
	const id = useId();

	return (
		<span className="relative inline-flex items-center gap-1">
			<span>{label}</span>
			<button
				type="button"
				aria-expanded={open}
				aria-controls={id}
				className="inline-flex size-5 items-center justify-center rounded-full text-zinc-400 transition hover:text-zinc-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:hover:text-zinc-200"
				onClick={() => setOpen((value) => !value)}
				onBlur={(event) => {
					if (!event.currentTarget.parentElement?.contains(event.relatedTarget)) {
						setOpen(false);
					}
				}}
			>
				<QuestionMarkCircleIcon className="size-5" />
				<span className="sr-only">Ver ayuda de {label}</span>
			</button>
			{open && (
				<span
					id={id}
					role="tooltip"
					tabIndex={-1}
					className="absolute left-0 top-7 z-20 w-72 rounded-lg border border-zinc-200 bg-white p-3 text-sm font-normal leading-5 text-zinc-600 shadow-xl dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
				>
					{children}
				</span>
			)}
		</span>
	);
}
