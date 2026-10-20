import { useState } from "react";
import { useForm } from "@inertiajs/react";
import { DialogTitle } from "@headlessui/react";
import { DocumentDuplicateIcon } from "@heroicons/react/24/outline";

import Modal from "@/Components/Catalyst/modal";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";

export default function ReplaceGdaModal({
	open,
	onClose,
	laboratoryPurchase,
	preview,
}) {
	const [selectedCouponId, setSelectedCouponId] = useState(
		preview?.balance_coupons?.[0]?.id ?? null,
	);

	const form = useForm({
		coupon_id: selectedCouponId,
	});

	const handleSubmit = (event) => {
		event.preventDefault();

		if (!selectedCouponId) {
			return;
		}

		form.transform((data) => ({
			...data,
			coupon_id: selectedCouponId,
		}));

		form.post(
			route("admin.laboratory-purchases.replace-gda", {
				laboratory_purchase: laboratoryPurchase.id,
			}),
			{
				preserveScroll: true,
				onSuccess: () => onClose(),
			},
		);
	};

	return (
		<Modal open={open} onClose={onClose} size="2xl">
			<form onSubmit={handleSubmit} className="space-y-6">
				<div className="flex items-start gap-3">
					<DocumentDuplicateIcon className="mt-1 size-6 shrink-0 text-violet-600" />
					<div>
						<DialogTitle className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
							Crear pedido de reemplazo en GDA
						</DialogTitle>
						<Text className="mt-1">
							Se creará un <Strong>pedido nuevo</Strong> copiando la información
							del #{laboratoryPurchase.id} y se enviará a GDA con un{" "}
							<Strong>ID distinto</Strong>. El pedido original conservará el
							pago capturado como referencia. No se enviará correo al paciente.
						</Text>
						{preview?.replacement_note && (
							<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
								{preview.replacement_note}
							</Text>
						)}
					</div>
				</div>

				<DescriptionList>
					<DescriptionTerm>Pedido origen</DescriptionTerm>
					<DescriptionDetails>#{laboratoryPurchase.id}</DescriptionDetails>

					<DescriptionTerm>Cliente</DescriptionTerm>
					<DescriptionDetails>
						{preview?.user?.full_name}
						{preview?.user?.email ? ` · ${preview.user.email}` : ""}
					</DescriptionDetails>

					<DescriptionTerm>Total</DescriptionTerm>
					<DescriptionDetails>
						{preview?.purchase?.formatted_total}
					</DescriptionDetails>

					{preview?.transaction && (
						<>
							<DescriptionTerm>Pago en pedido original</DescriptionTerm>
							<DescriptionDetails>
								{preview.transaction.formatted_amount} ·{" "}
								{preview.transaction.payment_method}
							</DescriptionDetails>
						</>
					)}

					{preview?.appointment && (
						<>
							<DescriptionTerm>Cita</DescriptionTerm>
							<DescriptionDetails>
								Se clonará la cita previa al pedido nuevo
								{preview.appointment.formatted_appointment_date
									? ` (${preview.appointment.formatted_appointment_date})`
									: ""}
								.
							</DescriptionDetails>
						</>
					)}
				</DescriptionList>

				<div>
					<Text className="mb-2 font-medium">Estudios a enviar a GDA</Text>
					<ul className="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
						{(preview?.gda_items ?? []).map((item) => (
							<li
								key={item.gda_id}
								className="flex items-center justify-between gap-4 px-4 py-2 text-sm"
							>
								<span>
									{item.name}{" "}
									<span className="text-zinc-500">({item.gda_id})</span>
								</span>
								<span>{item.formatted_price}</span>
							</li>
						))}
					</ul>
				</div>

				{(preview?.block_reasons ?? []).length > 0 && !preview?.eligible && (
					<div className="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 dark:border-amber-800 dark:bg-amber-950/30">
						<Text className="font-medium text-amber-900 dark:text-amber-100">
							Aún no se puede crear el reemplazo:
						</Text>
						<ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800 dark:text-amber-200">
							{preview.block_reasons.map((reason) => (
								<li key={reason}>{reason}</li>
							))}
						</ul>
					</div>
				)}

				<div>
					<Text className="mb-2 font-medium">Saldo a favor para el pedido nuevo</Text>
					{(preview?.balance_coupons ?? []).length === 0 ? (
						<Text className="text-red-600">
							No hay saldo a favor aplicable para este total.
						</Text>
					) : (
						<div className="space-y-2">
							{preview.balance_coupons.map((coupon) => (
								<label
									key={coupon.id}
									className="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 dark:border-zinc-700"
								>
									<input
										type="radio"
										name="coupon_id"
										value={coupon.id}
										checked={selectedCouponId === coupon.id}
										onChange={() => setSelectedCouponId(coupon.id)}
										className="size-4"
									/>
									<div className="flex flex-1 items-center justify-between gap-4">
										<span>
											{coupon.concept ?? "Saldo a favor"} ·{" "}
											{coupon.formatted_remaining}
										</span>
									</div>
								</label>
							))}
						</div>
					)}
				</div>

				<div className="flex flex-wrap justify-end gap-3">
					<Button type="button" outline onClick={onClose}>
						Cancelar
					</Button>
					<Button
						type="submit"
						color="violet"
						disabled={
							form.processing ||
							!selectedCouponId ||
							(preview?.balance_coupons ?? []).length === 0
						}
					>
						{form.processing
							? "Creando reemplazo…"
							: "Crear pedido de reemplazo"}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
