import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { BeakerIcon } from "@heroicons/react/24/outline";

export default function EmptyPendingPurchases() {
	return (
		<Card className="flex flex-col items-center px-6 py-10 text-center sm:px-10 sm:py-14">
			<span className="rounded-full bg-famedic-lime/35 p-4 text-famedic-darker dark:bg-famedic-lime/15 dark:text-famedic-lime">
				<BeakerIcon className="size-10" aria-hidden="true" />
			</span>
			<Subheading className="mt-5">
				No tienes compras pendientes
			</Subheading>
			<Text className="mt-2 max-w-lg">
				Cuando dejes estudios guardados o un checkout sin terminar,
				podrás retomarlos desde aquí.
			</Text>
			<Button
				href={route("laboratory-brand-selection")}
				color="famedic"
				className="mt-6 w-full sm:w-auto"
			>
				Explorar laboratorios
			</Button>
		</Card>
	);
}
