import Card from "@/Components/Card";
import { Button } from "@/Components/Catalyst/button";
import {
	CalendarDaysIcon,
	ClockIcon,
	MapPinIcon,
	BuildingStorefrontIcon,
	PhoneIcon,
	ChatBubbleLeftRightIcon,
} from "@heroicons/react/24/outline";

/** WhatsApp México: número que indicaste (554) 057 2139 → 525540572139 */
const WHATSAPP_E164 = "525540572139";
const WHATSAPP_DISPLAY = "+52 (554) 057 2139";

function openConciergeWhatsApp(purchaseId) {
	const message =
		purchaseId != null
			? `Hola, tengo una consulta sobre mi orden de laboratorio #${purchaseId}`
			: "Hola, tengo una consulta sobre mi orden de laboratorio";
	const url = `https://wa.me/${WHATSAPP_E164}?text=${encodeURIComponent(message)}`;
	window.open(url, "_blank", "noopener,noreferrer");
}

export default function AppointmentSummary({ appointment, purchaseId }) {
	const store = appointment?.laboratory_store;
	const appointmentDate =
		appointment?.formatted_appointment_date || appointment?.appointment_date || "Por confirmar";

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-6">
			<h2 className="mb-4 text-lg font-semibold text-zinc-900 dark:text-white">Cita</h2>

			<div className="min-w-0 space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-slate-700 dark:bg-slate-800 sm:p-4">
				<div className="flex min-w-0 items-start gap-2 text-sm font-medium text-zinc-800 dark:text-slate-100">
					<CalendarDaysIcon className="mt-0.5 size-4 shrink-0 text-famedic-500" />
					<span className="min-w-0 break-words">Fecha: {appointmentDate}</span>
				</div>
				{appointment?.formatted_appointment_hour && (
					<div className="flex min-w-0 items-start gap-2 text-sm text-zinc-700 dark:text-slate-200">
						<ClockIcon className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400" />
						<span className="min-w-0 break-words">Hora: {appointment.formatted_appointment_hour}</span>
					</div>
				)}
				{store?.name && (
					<div className="flex min-w-0 items-start gap-2 text-sm text-zinc-700 dark:text-slate-200">
						<BuildingStorefrontIcon className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400" />
						<span className="min-w-0 break-words">Sucursal: {store.name}</span>
					</div>
				)}
				{store?.address && (
					<div className="flex min-w-0 items-start gap-2 text-sm text-zinc-700 dark:text-slate-200">
						<MapPinIcon className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400" />
						<span className="min-w-0 break-words">{store.address}</span>
					</div>
				)}
				{store?.phone && (
					<div className="flex min-w-0 items-start gap-2 text-sm text-zinc-700 dark:text-slate-200">
						<PhoneIcon className="mt-0.5 size-4 shrink-0 text-zinc-500 dark:text-slate-400" />
						<span className="min-w-0 break-all">Teléfono: {store.phone}</span>
					</div>
				)}
			</div>

			<div className="mt-4 space-y-2">
				<Button
					outline
					type="button"
					className="w-full max-w-full justify-center sm:w-auto"
					onClick={() => openConciergeWhatsApp(purchaseId)}
				>
					<ChatBubbleLeftRightIcon className="size-4" />
					WhatsApp concierge
				</Button>
				<p className="text-xs text-zinc-500 dark:text-slate-400">{WHATSAPP_DISPLAY}</p>
			</div>
		</Card>
	);
}
