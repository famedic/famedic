import {
	BuildingStorefrontIcon,
	CalendarIcon,
	ChevronLeftIcon,
} from "@heroicons/react/16/solid";
import { PencilIcon } from "@heroicons/react/24/outline";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";

const STATUS_CONFIG = {
	completed: { label: "Completada", color: "emerald" },
	pending: { label: "Pendiente", color: "amber" },
	cancelled: { label: "Cancelada", color: "red" },
};

export default function AppointmentHeader({
	appointment,
	status,
	onEdit,
	showEditButton = true,
	actions = null,
}) {
	const statusConfig = STATUS_CONFIG[status] ?? STATUS_CONFIG.pending;
	const patientName =
		appointment.patient_name ||
		appointment.customer?.user?.full_name ||
		"Paciente";

	return (
		<div className="rounded-xl border border-zinc-200/70 bg-white/90 p-3 shadow-sm sm:p-4 dark:border-zinc-800 dark:bg-zinc-900/75">
			<a
				href={route("admin.laboratory-appointments.index")}
				className="mb-2 inline-flex items-center gap-1 text-xs font-medium text-zinc-500 transition hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-sky-500 dark:text-zinc-400 dark:hover:text-zinc-100"
			>
				<ChevronLeftIcon className="size-3.5 fill-current" />
				Citas / Detalle
			</a>
			<div className="flex flex-wrap items-start justify-between gap-3">
				<div className="flex min-w-0 items-start gap-3">
					<LaboratoryBrandCard
						className="w-20 shrink-0 sm:w-24"
						src={`/images/gda/GDA-${appointment.brand.toUpperCase()}.png`}
					/>
					<div className="min-w-0 space-y-1">
						<div className="flex flex-wrap items-center gap-3">
							<h1 className="text-lg font-semibold text-zinc-900 sm:text-xl dark:text-zinc-100">
								Cita de {patientName}
							</h1>
							<Badge color={statusConfig.color}>
								{statusConfig.label}
							</Badge>
						</div>
						<div className="flex flex-wrap gap-x-3 gap-y-1 text-sm text-zinc-600 dark:text-zinc-300">
							<span className="font-medium text-zinc-800 dark:text-zinc-200">
								{appointment.brand?.toUpperCase?.() ??
									"Laboratorio"}
							</span>
							<span className="inline-flex items-center gap-2">
								<BuildingStorefrontIcon className="size-4 fill-zinc-400 dark:fill-zinc-500" />
								{appointment.laboratory_store?.name ??
									"Sin sucursal asignada"}
							</span>
							<span className="inline-flex items-center gap-2">
								<CalendarIcon className="size-4 fill-zinc-400 dark:fill-zinc-500" />
								{appointment.formatted_appointment_date ??
									"Sin fecha de cita"}
							</span>
						</div>
					</div>
				</div>
				{showEditButton && (
					<Button outline onClick={onEdit}>
						<PencilIcon />
						Editar cita
					</Button>
				)}
				{actions && (
					<div className="flex shrink-0 flex-wrap items-center justify-start gap-2 sm:justify-end">
						{actions}
					</div>
				)}
			</div>
		</div>
	);
}
