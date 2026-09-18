import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import { BuildingStorefrontIcon, MapPinIcon } from "@heroicons/react/24/outline";

export default function StoresDirectoryPrompt({ purchase, hasPendingAppointment = false }) {
	const brand = purchase?.brand;

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<div className="mb-4 flex items-start gap-3">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-600 dark:bg-slate-800 dark:text-slate-300">
					<BuildingStorefrontIcon className="size-5" aria-hidden="true" />
				</div>
				<div className="min-w-0">
					<h2 className="text-lg font-semibold text-zinc-900 dark:text-white">Sucursales</h2>
					{hasPendingAppointment && (
						<p className="mt-2 text-sm font-medium text-zinc-800 dark:text-slate-100">
							Tu sucursal todavía no está confirmada.
						</p>
					)}
					<p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-slate-400">
						Consulta las sucursales disponibles para realizar tus estudios.
					</p>
					<p className="mt-2 text-sm leading-relaxed text-zinc-600 dark:text-slate-400">
						Si necesitas acudir a una sucursal, aquí puedes consultar las opciones disponibles.
					</p>
				</div>
			</div>

			<Button
				outline
				href={route("laboratory-stores.index", { brand })}
				className="w-full max-w-full justify-center sm:w-auto"
			>
				<MapPinIcon className="size-4" />
				Consultar sucursales
			</Button>
		</Card>
	);
}
