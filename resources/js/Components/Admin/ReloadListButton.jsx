import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function ReloadListButton({ processing, ...props }) {
	return (
		<Button color="sky" className="w-full shrink-0" disabled={processing} {...props}>
			<ArrowPathIcon className={processing ? "animate-spin" : ""} />
			Recargar lista
		</Button>
	);
}
