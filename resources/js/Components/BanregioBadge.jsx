import { Badge } from "@/Components/Catalyst/badge";
import { CreditCardIcon } from "@heroicons/react/16/solid";

export default function BanregioBadge({ children = "Banregio Colecto", className = "" }) {
	return (
		<Badge color="orange" className={className}>
			<CreditCardIcon className="size-4 shrink-0" data-slot="icon" />
			{children}
		</Badge>
	);
}
