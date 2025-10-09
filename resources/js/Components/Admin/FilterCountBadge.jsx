import { Badge } from "@/Components/Catalyst/badge";
import { FunnelIcon } from "@heroicons/react/24/outline";

export default function FilterCountBadge({ count }) {
	if (count === 0) return null;

	return (
		<Badge color="slate">
			<FunnelIcon className="size-3" />
			{count}
		</Badge>
	);
}
