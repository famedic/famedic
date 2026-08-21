import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import {
	ArrowPathIcon,
	ArrowLeftIcon,
} from "@heroicons/react/16/solid";

export default function DashboardHeader({
	cartsIndexUrl,
	onRefresh,
	refreshing = false,
	generatedAt,
}) {
	return (
		<div className="flex flex-wrap items-start justify-between gap-4">
			<div className="space-y-2">
				<Button href={cartsIndexUrl} outline className="inline-flex items-center gap-2">
					<ArrowLeftIcon className="size-4" />
					Volver al listado
				</Button>
				<div>
					<Heading>Dashboard Comercial</Heading>
					<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
						Monitoreo ejecutivo de ventas, conversión y abandono de
						carritos.
					</Text>
					{generatedAt ? (
						<Text className="mt-1 text-xs text-zinc-400">
							Actualizado {generatedAt}
						</Text>
					) : null}
				</div>
			</div>
			<div className="flex flex-wrap gap-2">
				<Button outline onClick={onRefresh} disabled={refreshing}>
					<ArrowPathIcon className="size-4" />
					Actualizar
				</Button>
			</div>
		</div>
	);
}
