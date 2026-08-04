import { Badge } from "@/Components/Catalyst/badge";

const COLOR_MAP = {
	amber: "amber",
	sky: "sky",
	lime: "lime",
	red: "red",
	zinc: "zinc",
	green: "green",
	blue: "blue",
};

export default function BillingStatusBadge({ status, label, color }) {
	if (!label && !status) {
		return <span className="text-zinc-400 dark:text-zinc-500">—</span>;
	}

	return (
		<Badge color={COLOR_MAP[color] || "zinc"} className="whitespace-nowrap">
			{label || status}
		</Badge>
	);
}

export function BillingDocumentStatus({ status, label, color, hasPdf, hasXml }) {
	return (
		<div className="flex flex-col gap-1">
			<BillingStatusBadge status={status} label={label} color={color} />
			<div className="flex gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
				<span aria-label={hasPdf ? "Con PDF" : "Sin PDF"}>
					PDF {hasPdf ? "✓" : "—"}
				</span>
				<span aria-label={hasXml ? "Con XML" : "Sin XML"}>
					XML {hasXml ? "✓" : "—"}
				</span>
			</div>
		</div>
	);
}
