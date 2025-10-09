import { Badge } from "@/Components/Catalyst/badge";
import { CheckCircleIcon, XCircleIcon } from "@heroicons/react/16/solid";

export default function MedicalAttentionBadge({ isActive, children }) {
	return (
		<Badge color={isActive ? "famedic-lime" : "slate"}>
			{isActive ? (
				<CheckCircleIcon className="size-4" />
			) : (
				<XCircleIcon className="size-4" />
			)}
			{children}
		</Badge>
	);
}