import { ClockIcon } from "@heroicons/react/16/solid";
import AppointmentSummary from "./AppointmentSummary";

export default function AppointmentSidebar({ appointment, summary }) {
	return (
		<div className="space-y-4">
			<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
				<h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Confirmacion de cita
				</h3>
				<div className="space-y-3 text-sm">
					<Item label="Fecha" value={appointment.formatted_appointment_date} />
					<Item label="Sucursal" value={appointment.laboratory_store?.name} />
					<Item label="Direccion" value={appointment.laboratory_store?.address} />
					<Item
						label="Fecha de solicitud"
						value={
							<span className="inline-flex items-center gap-2">
								<ClockIcon className="size-4 fill-zinc-500" />
								{appointment.formatted_created_at}
							</span>
						}
					/>
				</div>
			</section>

			<section className="rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
				<h3 className="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
					Notas del cliente
				</h3>
				<p className="text-sm text-zinc-700 dark:text-zinc-300">
					{appointment.notes || "Sin notas compartidas."}
				</p>
			</section>

			<AppointmentSummary summary={summary} />
		</div>
	);
}

function Item({ label, value }) {
	return (
		<div className="border-b border-zinc-200/70 pb-2 dark:border-zinc-800">
			<p className="text-xs text-zinc-500 dark:text-zinc-400">{label}</p>
			<p className="text-sm text-zinc-900 dark:text-zinc-100">{value || "..."}</p>
		</div>
	);
}
