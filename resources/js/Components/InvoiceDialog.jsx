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

function isPdfFile(file) {
	return (
		file.type === "application/pdf" || /\.pdf$/i.test(file.name)
	);
}

function isXmlFile(file) {
	return (
		["text/xml", "application/xml"].includes(file.type) ||
		/\.xml$/i.test(file.name)
	);
}

export default function InvoiceDialog({
	storeRoute,
	invoiceRoute,
	invoiceXmlRoute = null,
	invoiceRequest,
	hasInvoice,
	className = "",
}) {
	const [isOpen, setIsOpen] = useState(false);
	const [showChangeInvoiceButton, setShowChangeInvoiceButton] =
		useState(!!invoiceRoute);
	const [selectionError, setSelectionError] = useState(null);

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
	const hasBothFiles = Boolean(data.invoice && data.invoice_xml);
	const hasAnyFile = Boolean(data.invoice || data.invoice_xml);
	const canSubmit = hasInvoice ? hasAnyFile : hasBothFiles;

	const submit = (e) => {
		e.preventDefault();

		if (processing) {
			return;
		}

		if (!hasInvoice && !hasBothFiles) {
			setSelectionError(
				"Debes seleccionar un archivo PDF y un archivo XML para guardar la factura.",
			);
			return;
		}

		if (hasInvoice && !hasAnyFile) {
			setSelectionError(
				"Debes seleccionar al menos un archivo PDF o XML para actualizar la factura.",
			);
			return;
		}

		post(storeRoute, {
			preserveScroll: true,
			forceFormData: true,
			onSuccess: () => {
				reset();
				setSelectionError(null);
				setIsOpen(false);
			},
		});
	};

	useEffect(() => {
		if (isOpen) {
			setShowChangeInvoiceButton(!!invoiceRoute);
			reset();
			clearErrors();
			setSelectionError(null);
		}
	}, [isOpen]);

	const handleFilesChange = (e) => {
		const files = Array.from(e.target.files ?? []);

		if (files.length === 0) {
			setData({
				invoice: null,
				invoice_xml: null,
			});
			setSelectionError(null);
			clearErrors(["invoice", "invoice_xml"]);
			return;
		}

		const pdfFiles = files.filter(isPdfFile);
		const xmlFiles = files.filter(isXmlFile);
		const otherFiles = files.filter(
			(file) => !isPdfFile(file) && !isXmlFile(file),
		);

		if (otherFiles.length > 0) {
			setSelectionError(
				"Solo se permiten archivos PDF y XML. Selecciona un PDF y un XML.",
			);
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		if (pdfFiles.length > 1 || xmlFiles.length > 1) {
			setSelectionError(
				"Selecciona exactamente un archivo PDF y un archivo XML.",
			);
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		const pdfFile = pdfFiles[0] ?? null;
		const xmlFile = xmlFiles[0] ?? null;

		if (!hasInvoice && (!pdfFile || !xmlFile)) {
			setSelectionError(
				"Debes seleccionar un archivo PDF y un archivo XML en la misma operación.",
			);
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		if (hasInvoice && !pdfFile && !xmlFile) {
			setSelectionError(
				"Debes seleccionar al menos un archivo PDF o XML.",
			);
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		if (pdfFile.size > PDF_MAX_BYTES) {
			setSelectionError("El archivo PDF no debe superar los 10MB.");
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		if (xmlFile.size > XML_MAX_BYTES) {
			setSelectionError("El archivo XML no debe superar los 5MB.");
			e.target.value = "";
			setData({
				invoice: null,
				invoice_xml: null,
			});
			return;
		}

		setSelectionError(null);
		clearErrors(["invoice", "invoice_xml"]);
		setData({
			invoice: pdfFile,
			invoice_xml: xmlFile,
		});
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
							? "Puedes reemplazar el PDF, el XML o ambos. Solo se actualizan los archivos que envíes."
							: "Selecciona el PDF y el XML de la factura en una misma operación. Ambos archivos son obligatorios."}
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

						{invoiceRoute && showChangeInvoiceButton ? (
							<Field>
								<Label>Factura</Label>
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
							</Field>
						) : (
							<Field>
								<Label>Archivos de factura</Label>
								<Input
									invalid={
										!!selectionError ||
										!!errors.invoice ||
										!!errors.invoice_xml
									}
									dusk="invoice_files"
									type="file"
									multiple
									accept=".pdf,.xml,application/pdf,text/xml,application/xml"
									onChange={handleFilesChange}
								/>
								<Description className="mt-1">
									{hasInvoice
										? "Puedes seleccionar PDF, XML o ambos. PDF máx. 10MB • XML máx. 5MB"
										: "Selecciona un PDF y un XML juntos. Obligatorios. PDF máx. 10MB • XML máx. 5MB"}
								</Description>

								{(selectedPdfName || selectedXmlName) && (
									<div className="mt-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-slate-700 dark:bg-slate-900/40">
										<Text className="text-sm font-medium">
											Archivos seleccionados
										</Text>
										<ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-zinc-600 dark:text-slate-300">
											<li>
												PDF:{" "}
												{selectedPdfName ??
													"pendiente de seleccionar"}
											</li>
											<li>
												XML:{" "}
												{selectedXmlName ??
													"pendiente de seleccionar"}
											</li>
										</ul>
									</div>
								)}

								{selectionError && (
									<ErrorMessage>
										{selectionError}
									</ErrorMessage>
								)}
								{errors.invoice && (
									<ErrorMessage>
										{errors.invoice}
									</ErrorMessage>
								)}
								{errors.invoice_xml && (
									<ErrorMessage>
										{errors.invoice_xml}
									</ErrorMessage>
								)}
							</Field>
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
								disabled={processing || !canSubmit}
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
