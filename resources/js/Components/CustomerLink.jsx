import { Button } from "@/Components/Catalyst/button";
import { UserCircleIcon } from "@heroicons/react/16/solid";

export default function CustomerLink({ href, children }) {
	return (
		<Button outline href={href}>
			<UserCircleIcon />
			{children}
		</Button>
	);
}
