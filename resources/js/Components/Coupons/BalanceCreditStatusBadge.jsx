import { Badge } from "@/Components/Catalyst/badge";
import { getBalanceCreditStatusBadge } from "@/lib/couponPatientUi";

export default function BalanceCreditStatusBadge({
	primaryReason,
	applied = false,
	className = "",
}) {
	const { label, color } = getBalanceCreditStatusBadge(primaryReason, applied);

	return (
		<Badge color={color} className={["shrink-0 text-xs", className].join(" ")}>
			{label}
		</Badge>
	);
}
