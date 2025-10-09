import { Badge } from "@/Components/Catalyst/badge";
import { EnvelopeOpenIcon } from "@heroicons/react/16/solid";

export default function ReferralBadge({ customer, truncate = false }) {
	if (!customer.user?.referred_by) return null;

	const referrerName =
		customer.user?.referrer?.full_name || customer.user?.referrer?.email;

	return (
		<Badge color="slate" className={truncate ? "max-w-32" : ""}>
			<EnvelopeOpenIcon className="size-4 shrink-0" data-slot="icon" />
			{truncate ? (
				<span className="truncate">{referrerName}</span>
			) : (
				referrerName
			)}
		</Badge>
	);
}
