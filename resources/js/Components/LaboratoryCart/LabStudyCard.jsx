import { Badge } from "@/Components/Catalyst/badge";
import { Text, Strong } from "@/Components/Catalyst/text";
import { InformationCircleIcon } from "@heroicons/react/20/solid";
import { TrashIcon } from "@heroicons/react/24/outline";
import Card from "@/Components/Card";

export default function LabStudyCard({
	name,
	formattedPrice,
	requiresAppointment = false,
	onRemove,
}) {
	return (
		<Card
			as="li"
			className="flex items-start justify-between gap-3 p-4 sm:p-5"
		>
			<div className="min-w-0 flex-1">
				<div className="flex flex-wrap items-start justify-between gap-2">
					<Text className="font-semibold text-zinc-950 dark:text-white">
						{name}
					</Text>
					<Strong className="shrink-0 text-base text-famedic-dark dark:text-white">
						{formattedPrice}
					</Strong>
				</div>
				{requiresAppointment && (
					<Badge color="sky" className="mt-2">
						<InformationCircleIcon
							aria-hidden="true"
							className="size-4 text-famedic-light"
						/>
						Requiere cita
					</Badge>
				)}
			</div>
			<button
				type="button"
				onClick={onRemove}
				className="-m-2 inline-flex min-h-11 min-w-11 items-center justify-center rounded-lg p-2 text-zinc-500 transition hover:bg-zinc-100 hover:text-red-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-famedic-light dark:hover:bg-slate-800"
				aria-label={`Quitar ${name} del carrito`}
			>
				<TrashIcon aria-hidden="true" className="size-5" />
				<span className="sr-only">Quitar</span>
			</button>
		</Card>
	);
}
