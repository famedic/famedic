import { NoSymbolIcon } from "@heroicons/react/24/solid";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import Card from "@/Components/Card";
import clsx from "clsx";

export default function EmptyListCard({
	heading = "No hay resultados",
	message = "Intenta cambiar tu búsqueda.",
	className,
}) {
	return (
		<Card
			className={clsx(
				"flex flex-col items-center justify-center gap-4 p-6 sm:p-8 lg:p-12",
				className,
			)}
		>
			<NoSymbolIcon className="size-12 fill-zinc-500 dark:fill-slate-400" />

			<Subheading>{heading}</Subheading>

			<Text className="max-w-sm text-center">{message}</Text>
		</Card>
	);
}
