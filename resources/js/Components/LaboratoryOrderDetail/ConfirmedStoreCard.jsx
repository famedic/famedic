import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import {
	BuildingStorefrontIcon,
	ClockIcon,
	MapPinIcon,
	PhoneIcon,
} from "@heroicons/react/24/outline";
import {
	formatStoreHours,
	formatStoreLocationLines,
} from "@/lib/laboratoryOrderStoreUi";

export default function ConfirmedStoreCard({ store, appointment = null }) {
	if (!store) return null;

	const locationLines = formatStoreLocationLines(store);
	const hours = formatStoreHours(store);
	const mapsUrl = store.google_maps_url?.trim() || null;
	const appointmentDate =
		appointment?.formatted_appointment_date || appointment?.appointment_date || null;

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<div className="mb-4 flex items-start gap-3">
				<div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-famedic-lime/15 text-famedic-dark dark:bg-famedic-lime/10 dark:text-famedic-lime">
					<MapPinIcon className="size-5" aria-hidden="true" />
				</div>
				<div className="min-w-0">
					<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
						Sucursal confirmada para tu cita
					</p>
					<h2 className="mt-1 text-lg font-semibold text-zinc-900 dark:text-white">
						{store.name || "Sucursal"}
					</h2>
					<p className="mt-1 text-sm text-zinc-600 dark:text-slate-400">
						Esta es la sucursal confirmada para tu cita.
					</p>
				</div>
			</div>

			<div className="space-y-4 rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-slate-700 dark:bg-slate-800/40">
				{locationLines.length > 0 && (
					<div className="flex min-w-0 items-start gap-2.5 text-sm text-zinc-800 dark:text-slate-100">
						<BuildingStorefrontIcon
							className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400"
							aria-hidden="true"
						/>
						<div className="min-w-0 space-y-0.5 break-words">
							{locationLines.map((line) => (
								<p key={line}>{line}</p>
							))}
						</div>
					</div>
				)}

				{store.phone && (
					<div className="flex min-w-0 items-start gap-2.5 text-sm text-zinc-800 dark:text-slate-100">
						<PhoneIcon
							className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400"
							aria-hidden="true"
						/>
						<p className="min-w-0 break-all">{store.phone}</p>
					</div>
				)}

				{hours && (
					<div className="flex min-w-0 items-start gap-2.5 text-sm text-zinc-800 dark:text-slate-100">
						<ClockIcon
							className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400"
							aria-hidden="true"
						/>
						<div className="min-w-0">
							<p className="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-slate-400">
								Horarios
							</p>
							<p className="mt-1 break-words">{hours}</p>
						</div>
					</div>
				)}

				{appointmentDate && (
					<div className="border-t border-zinc-200 pt-3 text-sm text-zinc-700 dark:border-slate-600 dark:text-slate-200">
						<span className="font-medium text-zinc-900 dark:text-white">Cita: </span>
						{appointmentDate}
					</div>
				)}
			</div>

			{mapsUrl && (
				<div className="mt-4">
					<Button
						outline
						href={mapsUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="w-full max-w-full justify-center sm:w-auto"
					>
						<MapPinIcon className="size-4" />
						Ver ubicación
					</Button>
				</div>
			)}
		</Card>
	);
}
