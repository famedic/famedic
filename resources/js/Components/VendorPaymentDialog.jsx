import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	Field,
	Label,
	Description,
	ErrorMessage,
} from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Anchor } from "@/Components/Catalyst/text";
import {
	ArrowPathIcon,
	DocumentTextIcon,
	ArrowsUpDownIcon,
} from "@heroicons/react/16/solid";

export default function VendorPaymentDialog({
	open,
	onClose,
	selectedPurchases,
	onConfirm,
	paidAt,
	onPaidAtChange,
	onProofChange,
	errors,
	processing,
	proofRoute,
	formattedSubtotal,
	formattedCommission,
	formattedTotal,
}) {
	const [wantsToChangeProof, setWantsToChangeProof] = useState(false);

	const showChangeProofButton = proofRoute && !wantsToChangeProof;

	return (
		<Dialog open={open} onClose={onClose}>
			<DialogTitle>Completar pago a proveedor</DialogTitle>
			<DialogBody>
				<div className="space-y-6">
					<Field>
						<Label>Fecha de pago</Label>
						<Input
							type="date"
							value={paidAt}
							onChange={(e) => onPaidAtChange(e.target.value)}
							invalid={!!errors?.paid_at}
						/>
						{errors?.paid_at && (
							<ErrorMessage>{errors.paid_at}</ErrorMessage>
						)}
					</Field>

					<Field>
						<Label>Comprobante de pago</Label>

						{proofRoute && showChangeProofButton ? (
							<div
								data-slot="control"
								className="flex flex-wrap gap-2"
							>
								<Anchor href={proofRoute} target="_blank">
									<Button outline type="button">
										<DocumentTextIcon />
										Ver comprobante
									</Button>
								</Anchor>
								<Button
									outline
									type="button"
									onClick={() => setWantsToChangeProof(true)}
								>
									<ArrowsUpDownIcon />
									Actualizar comprobante
								</Button>
							</div>
						) : (
							<>
								<Input
									type="file"
									accept="application/pdf,image/*"
									onChange={(e) => {
										const file = e.target.files[0];
										if (file) {
											onProofChange(file);
										}
									}}
									invalid={!!errors?.proof}
								/>
								<Description>
									PDF o imagen • Tamaño máximo: 10MB
								</Description>
								{errors?.proof && (
									<ErrorMessage>{errors.proof}</ErrorMessage>
								)}
							</>
						)}
					</Field>

					<div className="overflow-x-auto">
						<Table dense>
							<TableHead>
								<TableRow>
									<TableHeader>Orden</TableHeader>
									<TableHeader>Subtotal</TableHeader>
									<TableHeader>Comisión</TableHeader>
									<TableHeader className="text-right">
										Total
									</TableHeader>
								</TableRow>
							</TableHead>
							<TableBody>
								{selectedPurchases.map((purchase) => {
									const orderId =
										purchase.gda_order_id ||
										purchase.vitau_order_id;

									return (
										<TableRow key={purchase.id}>
											<TableCell>{orderId}</TableCell>
											<TableCell>
												{purchase.formatted_total}
											</TableCell>
											<TableCell>
												{purchase.formatted_commission}
											</TableCell>
											<TableCell className="text-right">
												{
													purchase.formatted_total_after_commission
												}
											</TableCell>
										</TableRow>
									);
								})}
								{formattedSubtotal &&
									formattedCommission &&
									formattedTotal && (
										<TableRow>
											<TableCell className="border-t border-zinc-950/10 font-semibold dark:border-white/10">
												Total
											</TableCell>
											<TableCell className="border-t border-zinc-950/10 font-semibold dark:border-white/10">
												{formattedSubtotal}
											</TableCell>
											<TableCell className="border-t border-zinc-950/10 font-semibold dark:border-white/10">
												{formattedCommission}
											</TableCell>
											<TableCell className="border-t border-zinc-950/10 text-right font-semibold dark:border-white/10">
												{formattedTotal}
											</TableCell>
										</TableRow>
									)}
							</TableBody>
						</Table>
					</div>
				</div>
			</DialogBody>
			<DialogActions>
				<Button plain onClick={onClose} disabled={processing}>
					Cancelar
				</Button>
				<Button onClick={onConfirm} disabled={processing}>
					Confirmar
					{processing && <ArrowPathIcon className="animate-spin" />}
				</Button>
			</DialogActions>
		</Dialog>
	);
}
