import { useState } from "react";
import { router } from "@inertiajs/react";
import { Dialog, DialogTitle, DialogBody, DialogActions } from "@/Components/Catalyst/dialog";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";
import { Field, Label } from "@/Components/Catalyst/fieldset";
import { Textarea } from "@/Components/Catalyst/textarea";

export default function CouponAuthorizationRejectModal({
	isOpen,
	couponId,
	approvalRequestId = null,
	onClose,
}) {
	const [reason, setReason] = useState("");
	const [processing, setProcessing] = useState(false);
	const [error, setError] = useState("");

	const submit = () => {
		if (processing) return;
		if (reason.trim().length < 10) {
			setError("El motivo debe tener al menos 10 caracteres.");
			return;
		}

		setProcessing(true);
		setError("");
		router.post(
			route("admin.coupons.authorizations.reject", couponId),
			{
				reason: reason.trim(),
				approval_request_id: approvalRequestId,
			},
			{
				preserveScroll: true,
				onError: (errors) => {
					setError(errors.reason || "No se pudo rechazar la solicitud.");
					setProcessing(false);
				},
				onFinish: () => setProcessing(false),
			},
		);
	};

	return (
		<Dialog
			open={isOpen}
			onClose={onClose}
			size="lg"
			afterLeave={() => {
				setReason("");
				setError("");
			}}
		>
			<DialogTitle>Rechazar solicitud</DialogTitle>
			<DialogBody className="space-y-4">
				<Text className="text-sm text-zinc-600 dark:text-zinc-400">
					El rechazo es definitivo. Indica el motivo para notificar al creador y a los demás
					autorizadores.
				</Text>
				{error && (
					<div className="rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/20 dark:text-red-200">
						{error}
					</div>
				)}
				<Field>
					<Label>Motivo del rechazo</Label>
					<Textarea
						value={reason}
						onChange={(e) => setReason(e.target.value)}
						rows={4}
						placeholder="Describe por qué se rechaza esta solicitud..."
					/>
				</Field>
			</DialogBody>
			<DialogActions>
				<Button outline onClick={onClose} disabled={processing}>
					Cancelar
				</Button>
				<Button color="red" onClick={submit} disabled={processing}>
					{processing ? "Rechazando..." : "Rechazar solicitud"}
				</Button>
			</DialogActions>
		</Dialog>
	);
}
