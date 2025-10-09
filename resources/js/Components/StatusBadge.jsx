import { Badge } from "@/Components/Catalyst/badge";
import { CheckCircleIcon, XCircleIcon } from "@heroicons/react/16/solid";

export default function StatusBadge({ 
	isActive, 
	activeText = "Completado", 
	inactiveText = "Pendiente",
	activeColor = "famedic-lime",
	inactiveColor = "slate"
}) {
	return (
		<Badge color={isActive ? activeColor : inactiveColor}>
			{isActive ? (
				<CheckCircleIcon className="size-4" />
			) : (
				<XCircleIcon className="size-4" />
			)}
			{isActive ? activeText : inactiveText}
		</Badge>
	);
}