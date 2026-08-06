import { Badge } from "@/Components/Catalyst/badge";
import { resolveConfidenceBadge } from "./confidenceHelpers";

const TONE_MAP = {
	emerald: "emerald",
	amber: "amber",
	orange: "orange",
	red: "red",
	zinc: "zinc",
};

/**
 * Visual confidence badge for a study / validation item.
 */
export default function ConfidenceBadge({ item, className = "" }) {
	const badge = resolveConfidenceBadge(item);
	const color = TONE_MAP[badge.tone] || "zinc";

	return (
		<Badge color={color} className={`!text-[10px] ${className}`.trim()}>
			<span aria-hidden className="mr-1">
				{badge.emoji}
			</span>
			{badge.label}
			{badge.percent != null ? ` · ${badge.percent}%` : ""}
		</Badge>
	);
}
