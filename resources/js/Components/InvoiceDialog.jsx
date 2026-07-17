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

const PDF_MAX_BYTES = 10 * 1024 * 1024;
const XML_MAX_BYTES = 5 * 1024 * 1024;

export default function InvoiceDialog({
	storeRoute,
	invoiceRoute,
	invoiceXmlRoute = null,
	invoiceRequest,
	hasInvoice,
	hasInvoiceXml = false,
	className = "",
}) {
	const [isOpen, setIsOpen] = useState(false);
	const [showChangeInvoiceButton, setShowChangeInvoiceButton] =
		useState(!!invoiceRoute);

	const {
		data,
		setData,
		post,
		processing,
		errors,
		setError,
		clearErrors,
		reset,
	} = useForm({
		invoice: null,
		invoice_xml: null,
	});

	const selectedPdfName = data.invoice?.name ?? null;
	const selectedXmlName = data.invoice_xml?.name ?? null;
	const hasSelectedFile = Boolean(data.invoice || data.invoice_xml);

	const submit = (e) => {
		e.preventDefault();

		if (processing) {
			return;
		}

		if (hasInvoice && !hasSelectedFile) {
			setError(
				"invoice",
				"Debes seleccionar al menos un archivo (PDF o XML) para actualizar la factura.",
			);
			return;
		}

		post(storeRoute, {
			preserveScroll: true,
			forceFormData: true,
			onSuccess: () => {
				reset();
				setIsOpen(false);
			},
		});
	};

	useEffect(() => {
		if (isOpen) {
			setShowChangeInvoiceButton(!!invoiceRoute);
			reset();
			clearErrors();
		}
	}, [isOpen]);

	const handlePdfChange = (e) => {
		const file = e.target.files?.[0];
		if (!file) {
			setData("invoice", null);
			return;
		}

		if (file.size > PDF_MAX_BYTES) {
			setError("invoice", "El archivo PDF no debe superar los 10MB.");
			e.target.value = "";
			return;
		}

		clearErrors("invoice");
		setData("invoice", file);
	};

	const handleXmlChange = (e) => {
		const file = e.target.files?.[0];
		if (!file) {
			setData("invoice_xml", null);
			return;
		}

		if (!/\.xml$/i.test(file.name)) {
			setError(
				"invoice_xml",
				"La factura XML debe ser un archivo con extensión .xml.",
			);
			e.target.value = "";
			return;
		}

		if (file.size > XML_MAX_BYTES) {
			setError("invoice_xml", "El archivo XML no debe superar los 5MB.");
			e.target.value = "";
			return;
		}

		clearErrors("invoice_xml");
		setData("invoice_xml", file);
	};

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
							? "Visualiza o actualiza el PDF y, si lo deseas, el XML de la factura. Puedes reemplazar uno o ambos archivos."
							: "Sube el archivo PDF de la factura. Ahora también puedes agregar el XML de forma opcional."}
					</DialogDescription>
					<DialogBody className="space-y-6">
						{invoiceRequest && (
							<SettingsCard as="div">
								<Subheading>{invoiceRequest.name}</Subheading>
								<Code>{invoiceRequest.rfc}</Code>
								<Text className="mb-3">
									CP {invoiceRequest.zipcode}
								</Text>
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
							<Label>Factura PDF</Label>

							{invoiceRoute && showChangeInvoiceButton ? (
								<div
									data-slot="control"
									className="flex flex-wrap gap-2"
								>
									<Anchor href={invoiceRoute} target="_blank">
										<Button outline type="button">
											<DocumentTextIcon />
											Ver PDF
										</Button>
									</Anchor>
									{invoiceXmlRoute && (
										<Anchor
											href={invoiceXmlRoute}
											target="_blank"
										>
											<Button outline type="button">
												<DocumentTextIcon />
												Ver XML
											</Button>
										</Anchor>
									)}
									<Button
										outline
										type="button"
										onClick={() =>
											setShowChangeInvoiceButton(false)
										}
									>
										<ArrowsUpDownIcon />
										Actualizar archivos
									</Button>
								</div>
							) : (
								<>
									<Input
										invalid={!!errors.invoice}
										dusk="invoice"
										type="file"
										accept="application/pdf,.pdf"
										onChange={handlePdfChange}
									/>
									<Description className="mt-1">
										{hasInvoice
											? "Opcional al actualizar. Si no eliges un PDF nuevo, se conserva el actual. Formato: PDF • Máx. 10MB"
											: "Obligatorio. Formato: PDF • Tamaño máximo: 10MB"}
									</Description>
									{selectedPdfName && (
										<Text className="mt-1 text-sm">
											Seleccionado: {selectedPdfName}
											{hasInvoice
												? " — se actualizará el PDF"
												: ""}
										</Text>
									)}
									{errors.invoice && (
										<ErrorMessage>
											{errors.invoice}
										</ErrorMessage>
									)}
								</>
							)}
						</Field>

						{(!showChangeInvoiceButton || !invoiceRoute) && (
							<Field>
								<div className="mb-1 flex items-center gap-2">
									<Label className="!mb-0">Factura XML</Label>
									<Badge color="lime">NEW</Badge>
								</div>
								<Input
									invalid={!!errors.invoice_xml}
									dusk="invoice_xml"
									type="file"
									accept=".xml,text/xml,application/xml"
									onChange={handleXmlChange}
								/>
								<Description className="mt-1">
									{hasInvoice
										? hasInvoiceXml
											? "Opcional. Si no eliges un XML nuevo, se conserva el actual. Formato: XML • Máx. 5MB"
											: "Opcional. Puedes agregar el XML ahora sin modificar el PDF. Formato: XML • Máx. 5MB"
										: "Opcional. Ahora también puedes agregar el XML de la factura junto con el PDF. Formato: XML • Máx. 5MB"}
								</Description>
								{selectedXmlName && (
									<Text className="mt-1 text-sm">
										Seleccionado: {selectedXmlName}
										{hasInvoice
											? hasInvoiceXml
												? " — se actualizará el XML"
												: " — se agregará el XML"
											: " — se guardará junto con el PDF"}
									</Text>
								)}
								{errors.invoice_xml && (
									<ErrorMessage>
										{errors.invoice_xml}
									</ErrorMessage>
								)}
							</Field>
						)}

						{hasInvoice &&
							!showChangeInvoiceButton &&
							hasSelectedFile && (
								<div className="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-slate-700 dark:bg-slate-900/40">
									<Text className="text-sm font-medium">
										Archivos que se actualizarán
									</Text>
									<ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600 dark:text-slate-300">
										{selectedPdfName && (
											<li>PDF: {selectedPdfName}</li>
										)}
										{selectedXmlName && (
											<li>XML: {selectedXmlName}</li>
										)}
										{!selectedPdfName && (
											<li>
												PDF: se conserva el archivo
												actual
											</li>
										)}
										{!selectedXmlName && (
											<li>
												XML:{" "}
												{hasInvoiceXml
													? "se conserva el archivo actual"
													: "sin cambios (sigue sin XML)"}
											</li>
										)}
									</ul>
								</div>
							)}
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
						{(!showChangeInvoiceButton || !invoiceRoute) && (
							<Button
								type="submit"
								disabled={
									processing ||
									(hasInvoice && !hasSelectedFile)
								}
							>
								Guardar
								{processing && (
									<ArrowPathIcon className="animate-spin" />
								)}
							</Button>
						)}
					</DialogActions>
				</form>
			</Dialog>
		</>
	);
}
