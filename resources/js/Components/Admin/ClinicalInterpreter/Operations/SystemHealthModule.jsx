import { Badge } from "@/Components/Catalyst/badge";

const STATUS_BADGE = {
	ok: { color: "emerald", label: "OK" },
	warn: { color: "amber", label: "Atención" },
	error: { color: "red", label: "Error" },
	info: { color: "zinc", label: "Info" },
};

export default function SystemHealthModule({ data }) {
	const checks = data?.checks || [];

	return (
		<div className="space-y-4">
			<p className="text-sm text-zinc-500">
				Estado operativo del AI Clinical Interpreter. Errores Vision/Matching/Checkout
				sin tabla dedicada se reportan con honestidad.
			</p>

			<ul className="grid gap-3 sm:grid-cols-2">
				{checks.map((check) => {
					const badge = STATUS_BADGE[check.status] || STATUS_BADGE.info;
					return (
						<li
							key={check.id}
							className="rounded-xl border border-zinc-200 bg-white px-4 py-4 dark:border-zinc-700 dark:bg-zinc-900"
						>
							<div className="flex items-start justify-between gap-2">
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{check.label}
								</p>
								<Badge color={badge.color} className="!text-[10px]">
									{badge.label}
								</Badge>
							</div>
							<p className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
								{check.detail}
							</p>
							{check.truth && (
								<p className="mt-1 text-[11px] text-zinc-400">
									Fuente · {check.truth}
								</p>
							)}
						</li>
					);
				})}
			</ul>
		</div>
	);
}
