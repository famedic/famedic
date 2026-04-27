import { BuildingStorefrontIcon, CalendarIcon } from "@heroicons/react/16/solid";
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
		appointment.patient_name || appointment.customer?.user?.full_name || "Paciente";

	return (
		<div className="space-y-4 rounded-2xl border border-zinc-200/70 bg-white/80 p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900/70">
			<div className="flex flex-wrap items-start justify-between gap-4">
				<div className="flex items-start gap-4">
					<LaboratoryBrandCard
						className="w-32 shrink-0"
						src={`/images/gda/GDA-${appointment.brand.toUpperCase()}.png`}
					/>
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-3">
							<h1 className="text-xl font-semibold text-zinc-900 dark:text-zinc-100">
								Cita de {patientName}
							</h1>
							<Badge color={statusConfig.color}>{statusConfig.label}</Badge>
						</div>
						<div className="flex flex-wrap gap-x-6 gap-y-2 text-sm text-zinc-600 dark:text-zinc-300">
							<span className="inline-flex items-center gap-2">
								<BuildingStorefrontIcon className="size-4 fill-zinc-400 dark:fill-zinc-500" />
								{appointment.laboratory_store?.name ?? "Sin sucursal asignada"}
							</span>
							<span className="inline-flex items-center gap-2">
								<CalendarIcon className="size-4 fill-zinc-400 dark:fill-zinc-500" />
								{appointment.formatted_appointment_date ?? "Sin fecha de cita"}
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
			</div>
			{actions && (
				<div className="flex flex-wrap items-center gap-3 border-t border-zinc-200/70 pt-4 dark:border-zinc-800">
					{actions}
				</div>
			)}
		</div>
	);
}
