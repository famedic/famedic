import { Link } from "@inertiajs/react";
import { Text } from "@/Components/Catalyst/text";

export default function SectionHeading({
	eyebrow,
	title,
	description,
	action = null,
}) {
	return (
		<div className="flex flex-wrap items-end justify-between gap-3">
			<div className="min-w-0 space-y-1">
				{eyebrow ? (
					<p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-zinc-400">
						{eyebrow}
					</p>
				) : null}
				<h2 className="font-poppins text-base font-semibold tracking-tight text-zinc-950 dark:text-white">
					{title}
				</h2>
				{description ? (
					<Text className="text-xs text-zinc-500 dark:text-zinc-400">
						{description}
					</Text>
				) : null}
			</div>
			{action}
		</div>
	);
}

export function QuietLink({ href, children }) {
	return (
		<Link
			href={href}
			className="group inline-flex items-center gap-1 text-xs font-semibold text-famedic-light transition hover:text-famedic-dark dark:hover:text-famedic-lime"
		>
			{children}
			<span className="transition group-hover:translate-x-0.5">→</span>
		</Link>
	);
}
