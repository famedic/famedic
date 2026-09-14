import { useState } from "react";
import { router } from "@inertiajs/react";
import { EnvelopeIcon } from "@heroicons/react/24/outline";
import { Button } from "@/Components/Catalyst/button";

export default function LaboratoryAppointmentEmailActions({
	appointment,
	hasPaidLaboratoryPurchase,
	className = null,
	compact = false,
}) {
	const [busyAction, setBusyAction] = useState(null);

	const postEmail = (routeName, actionKey) => {
		setBusyAction(actionKey);
		router.post(
			route(routeName, appointment.id),
			{},
			{
				preserveScroll: true,
				onFinish: () => setBusyAction(null),
			},
		);
	};

	const showPaymentSummary = !hasPaidLaboratoryPurchase;
	const showAppointmentInstructions =
		Boolean(appointment.confirmed_at) && hasPaidLaboratoryPurchase;

	if (!showPaymentSummary && !showAppointmentInstructions) {
		return null;
	}

	return (
		<>
			{showPaymentSummary && (
				<Button
					outline
					className={className}
					disabled={busyAction !== null}
					onClick={() =>
						postEmail(
							"admin.laboratory-appointments.send-payment-summary",
							"payment-summary",
						)
					}
				>
					<EnvelopeIcon />
					{busyAction === "payment-summary"
						? "Enviando…"
						: compact
							? "Enviar pago"
							: "Enviar resumen de pago"}
				</Button>
			)}
			{showAppointmentInstructions && (
				<Button
					outline
					className={className}
					disabled={busyAction !== null}
					onClick={() =>
						postEmail(
							"admin.laboratory-appointments.send-appointment-instructions",
							"appointment-instructions",
						)
					}
				>
					<EnvelopeIcon />
					{busyAction === "appointment-instructions"
						? "Enviando…"
						: compact
							? "Enviar indicación"
							: "Enviar indicación y cita"}
				</Button>
			)}
		</>
	);
}
