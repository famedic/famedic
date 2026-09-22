import { useState } from "react";
import { useForm } from "@inertiajs/react";
import { DialogTitle } from "@headlessui/react";
import { ArrowPathIcon } from "@heroicons/react/24/outline";

import Modal from "@/Components/Catalyst/modal";
import { Button } from "@/Components/Catalyst/button";
import { Text, Strong } from "@/Components/Catalyst/text";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";

export default function RecoverGdaModal({
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
			route("admin.laboratory-purchases.recover-gda", {
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
					<ArrowPathIcon className="mt-1 size-6 shrink-0 text-amber-600" />
					<div>
						<DialogTitle className="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
							Recuperar pedido en GDA
						</DialogTitle>
						<Text className="mt-1">
							Se reenviará la cotización a GDA sobre el mismo pedido{" "}
							<Strong>#{laboratoryPurchase.id}</Strong>, aplicando saldo a
							favor sin volver a cobrar. No se enviará correo al paciente.
						</Text>
					</div>
				</div>

				<DescriptionList>
					<DescriptionTerm>Cliente</DescriptionTerm>
					<DescriptionDetails>
						{preview?.user?.full_name}
						{preview?.user?.email ? ` · ${preview.user.email}` : ""}
					</DescriptionDetails>

					<DescriptionTerm>Total del pedido</DescriptionTerm>
					<DescriptionDetails>
						{preview?.purchase?.formatted_total}
					</DescriptionDetails>

					{preview?.transaction && (
						<>
							<DescriptionTerm>Pago ya capturado</DescriptionTerm>
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
								Se clonará automáticamente la cita previa
								{preview.appointment.formatted_appointment_date
									? ` (${preview.appointment.formatted_appointment_date})`
									: ""}
								{preview.appointment.store_name
									? ` en ${preview.appointment.store_name}`
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
							Aún no se puede ejecutar la recuperación:
						</Text>
						<ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-amber-800 dark:text-amber-200">
							{preview.block_reasons.map((reason) => (
								<li key={reason}>{reason}</li>
							))}
						</ul>
					</div>
				)}

				<div>
					<Text className="mb-2 font-medium">Saldo a favor disponible</Text>
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
										{coupon.code && (
											<span className="text-xs text-zinc-500">
												{coupon.code}
											</span>
										)}
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
						color="amber"
						disabled={
							form.processing ||
							!selectedCouponId ||
							(preview?.balance_coupons ?? []).length === 0
						}
					>
						{form.processing ? "Recuperando…" : "Recuperar en GDA"}
					</Button>
				</div>
			</form>
		</Modal>
	);
}
