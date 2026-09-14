import { ClockIcon } from "@heroicons/react/16/solid";
import { Badge } from "@/Components/Catalyst/badge";

export default function AppointmentSidebar({
	appointment,
	summary,
	actions = null,
}) {
	return (
		<section className="rounded-xl border border-zinc-200/70 bg-white/90 p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/75">
			<h2 className="mb-3 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
				Cita
			</h2>

			<div className="space-y-2 text-sm">
				<Row label="Estado">
					<Badge color={summary.statusColor}>
						{summary.statusLabel}
					</Badge>
				</Row>
				<Row label="Laboratorio">{summary.laboratory}</Row>
				<Row label="Estudios">{summary.totalStudies}</Row>
				<Row label="Pago">
					<Badge color={summary.paymentColor}>
						{summary.paymentLabel}
					</Badge>
				</Row>
			</div>

			{actions && (
				<div className="my-3 border-y border-zinc-200/70 py-3 dark:border-zinc-800">
					{actions}
				</div>
			)}

			<div className="space-y-2 text-sm">
				<Row label="Fecha">
					{appointment.formatted_appointment_date}
				</Row>
				<Row label="Sucursal">{appointment.laboratory_store?.name}</Row>
				<Row label="Direccion">
					{appointment.laboratory_store?.address}
				</Row>
				<Row label="Solicitud">
					<span className="inline-flex items-center justify-end gap-1.5">
						<ClockIcon className="size-3.5 fill-zinc-500" />
						{appointment.formatted_created_at}
					</span>
				</Row>
			</div>
		</section>
	);
}

function Row({ label, children }) {
	return (
		<div className="grid grid-cols-[5.5rem_minmax(0,1fr)] items-start gap-3 border-b border-zinc-200/60 pb-2 last:border-b-0 last:pb-0 dark:border-zinc-800">
			<span className="text-xs text-zinc-500 dark:text-zinc-400">
				{label}
			</span>
			<span className="min-w-0 text-right text-sm text-zinc-900 dark:text-zinc-100">
				{children || "---"}
			</span>
		</div>
	);
}
