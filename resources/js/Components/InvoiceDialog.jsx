import { useState, useEffect } from "react";
import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogDescription,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import {
	Field,
	Label,
	ErrorMessage,
	Description,
} from "@/Components/Catalyst/fieldset";
import { Input } from "@/Components/Catalyst/input";
import { Text, Code, Anchor } from "@/Components/Catalyst/text";
import { Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import {
	DocumentTextIcon,
	ArrowPathIcon,
	ArrowsUpDownIcon,
} from "@heroicons/react/16/solid";
import SettingsCard from "@/Components/SettingsCard";

export default function InvoiceDialog({
	storeRoute,
	invoiceRoute,
	invoiceRequest,
	hasInvoice,
	className = "",
}) {
	const [isOpen, setIsOpen] = useState(false);
	const [showChangeInvoiceButton, setShowChangeInvoiceButton] =
		useState(!!invoiceRoute);

	const { setData, post, processing, errors, setError, clearErrors } =
		useForm({
			invoice: null,
		});

	const submit = (e) => {
		e.preventDefault();

		if (!processing) {
			post(storeRoute, {
				preserveScroll: true,
				onSuccess: () => setIsOpen(false),
			});
		}
	};

	useEffect(() => {
		if (isOpen) {
			setShowChangeInvoiceButton(!!invoiceRoute);
		}
	}, [isOpen]);

	return (
		<>
			<Button
				outline
				onClick={() => setIsOpen(true)}
				className={className}
			>
				<DocumentTextIcon />
				{hasInvoice ? "Factura" : "Agregar factura"}
			</Button>

			<Dialog open={isOpen} onClose={() => setIsOpen(false)}>
				<form onSubmit={submit}>
					<DialogTitle>
						{hasInvoice ? "Gestionar factura" : "Agregar factura"}
					</DialogTitle>
					<DialogDescription>
						{hasInvoice
							? "Visualiza o actualiza el archivo PDF de la factura."
							: "Sube el archivo PDF de la factura."}
					</DialogDescription>
					<DialogBody className="space-y-6">
						{invoiceRequest && (
							<SettingsCard as="div">
								<Subheading>{invoiceRequest.name}</Subheading>
								<Code>{invoiceRequest.rfc}</Code>
								<Text className="mb-3">
									CP {invoiceRequest.zipcode}
								</Text>
								<Badge color="slate" className="mb-1 max-w-60">
									<span className="line-clamp-1">
										{invoiceRequest.formatted_tax_regime}
									</span>
								</Badge>
								<br />
								<Badge color="slate" className="max-w-60">
									{invoiceRequest.formatted_cfdi_use}
								</Badge>
								<br />
								<Anchor
									href={route(
										"invoice-requests.fiscal-certificate",
										{
											invoice_request: invoiceRequest,
										},
									)}
									target="_blank"
								>
									<Button
										className="my-4"
										type="button"
										outline
									>
										<DocumentTextIcon />
										Ver constancia
									</Button>
								</Anchor>
							</SettingsCard>
						)}
						<Field>
							<Label>Factura</Label>

							{invoiceRoute && showChangeInvoiceButton ? (
								<div
									data-slot="control"
									className="flex flex-wrap gap-2"
								>
									<Anchor href={invoiceRoute} target="_blank">
										<Button outline type="button">
											<DocumentTextIcon />
											Ver factura
										</Button>
									</Anchor>
									<Button
										outline
										type="button"
										onClick={() =>
											setShowChangeInvoiceButton(false)
										}
									>
										<ArrowsUpDownIcon />
										Actualizar factura
									</Button>
								</div>
							) : (
								<>
									<Input
										invalid={!!errors.invoice}
										dusk="invoice"
										type="file"
										accept="application/pdf"
										onChange={(e) => {
											const file = e.target.files[0];
											if (file) {
												// Check file size (10MB = 10 * 1024 * 1024 bytes)
												if (
													file.size >
													10 * 1024 * 1024
												) {
													setError(
														"invoice",
														"El archivo no debe superar los 10MB.",
													);
													return;
												}
												clearErrors("invoice");
												setData("invoice", file);
											}
										}}
									/>
									<Description className="mt-1">
										Formato: PDF • Tamaño máximo: 10MB
									</Description>
									{errors.invoice && (
										<ErrorMessage>
											{errors.invoice}
										</ErrorMessage>
									)}
								</>
							)}
						</Field>
					</DialogBody>
					<DialogActions>
						<Button
							autoFocus
							plain
							onClick={() => setIsOpen(false)}
							type="button"
						>
							Cerrar
						</Button>
						<Button type="submit" disabled={processing}>
							Guardar
							{processing && (
								<ArrowPathIcon className="animate-spin" />
							)}
						</Button>
					</DialogActions>
				</form>
			</Dialog>
		</>
	);
}
