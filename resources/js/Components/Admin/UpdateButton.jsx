import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon } from "@heroicons/react/16/solid";

export default function UpdateButton({ processing, ...props }) {
	return (
		<Button className="max-md:w-full" disabled={processing} {...props}>
			Actualizar resultados
			<ArrowPathIcon className={processing ? "animate-spin" : ""} />
		</Button>
	);
}