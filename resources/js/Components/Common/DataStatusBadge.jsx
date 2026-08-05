import clsx from "clsx";
import { DATA_STATUSES } from "./dataProvenanceConstants";

export default function DataStatusBadge({
	status = "unavailable",
	detail = null,
	compact = false,
	className = "",
}) {
	const meta = DATA_STATUSES[status] || DATA_STATUSES.unavailable;

	return (
		<span
			className={clsx(
				"inline-flex max-w-full flex-col gap-0.5 rounded-lg border px-2 py-1",
				meta.className,
				className,
			)}
			role="status"
		>
			<span className="text-[10px] font-bold uppercase tracking-wide">
				{meta.label}
			</span>
			{!compact ? (
				<span className="text-[11px] font-medium opacity-90">
					{detail || meta.detail}
				</span>
			) : null}
		</span>
	);
}
