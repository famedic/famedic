import { useForm, usePage } from "@inertiajs/react";
import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Anchor, Strong, Text, TextLink } from "@/Components/Catalyst/text";
import { Field, Label, ErrorMessage } from "@/Components/Catalyst/fieldset";
import { Listbox, ListboxOption, ListboxLabel, ListboxDescription } from "@/Components/Catalyst/listbox";
import {
	ArrowDownTrayIcon,
	ArrowPathIcon,
	ClockIcon,
	DocumentTextIcon,
	ReceiptPercentIcon,
} from "@heroicons/react/24/outline";

const CFDI_OPTIONS = [
	{ value: "G03", label: "G03", description: "Gastos en general" },
	{ value: "G01", label: "G01", description: "Adquisicion de mercancias" },
	{ value: "G02", label: "G02", description: "Devoluciones, descuentos o bonificaciones" },
	{ value: "P01", label: "P01", description: "Por definir" },
	{ value: "D01", label: "D01", description: "Honorarios medicos, dentales y gastos hospitalarios" },
	{ value: "D02", label: "D02", description: "Gastos de funeral" },
	{ value: "D03", label: "D03", description: "Donativos" },
	{ value: "D04", label: "D04", description: "Intereses reales pagados por creditos hipotecarios" },
	{ value: "D05", label: "D05", description: "Aportaciones voluntarias al SAR" },
	{ value: "D06", label: "D06", description: "Primas por seguros de gastos medicos" },
	{ value: "D07", label: "D07", description: "Gastos de transportacion escolar obligatoria" },
	{ value: "D08", label: "D08", description: "Depositos en cuentas para el ahorro" },
	{ value: "D09", label: "D09", description: "Pagos por servicios educativos (colegiaturas)" },
];

export default function InvoiceSection({ purchase, inlineForm = false }) {
	const { daysLeftToRequestInvoice, taxProfiles = [] } = usePage().props;
	const { data, setData, post, processing, errors } = useForm({
		tax_profile: null,
		cfdi_use: "",
	});

	const hasInvoice = Boolean(purchase?.invoice);
	const hasInvoiceRequest = Boolean(purchase?.invoice_request);
	const hasTaxProfiles = taxProfiles.length > 0;
	const canRequestInvoice =
		!hasInvoice && !hasInvoiceRequest && daysLeftToRequestInvoice > 0;
	const invoiceRecord = purchase?.invoice;
	const invoiceRequest = purchase?.invoice_request;
	const invoiceUploadedAt = invoiceRecord?.formatted_created_at || null;
	const invoiceRequestedAt = invoiceRequest?.formatted_created_at || null;

	const submitInvoiceRequest = (e) => {
		e.preventDefault();
		if (processing) return;
		post(
			route("laboratory-purchases.invoice-request", {
				laboratory_purchase: purchase,
			}),
			{ preserveScroll: true },
		);
	};

	return (
		<Card className="min-w-0 max-w-full overflow-hidden rounded-2xl p-4 shadow-sm sm:p-5">
			<div className="mb-4 flex min-w-0 flex-wrap items-start justify-between gap-2">
				<h3 className="min-w-0 flex-1 break-words text-base font-semibold text-zinc-900 dark:text-white">
					Facturas
				</h3>
				<Badge color={hasInvoice ? "green" : hasInvoiceRequest ? "blue" : "slate"} className="shrink-0">
					{hasInvoice ? "Disponible" : hasInvoiceRequest ? "Solicitada" : "Sin solicitar"}
				</Badge>
			</div>

			{hasInvoice && (
				<div className="space-y-3">
					<Anchor
						href={route("invoice", { invoice: invoiceRecord?.id ?? invoiceRecord })}
						target="_blank"
					>
						<Button outline className="w-full" type="button">
							<ArrowDownTrayIcon className="size-4" />
							Descargar factura
						</Button>
					</Anchor>

					<div className="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 dark:border-emerald-900/60 dark:bg-emerald-950/20">
						<div className="flex items-center gap-2">
							<DocumentTextIcon className="size-5 text-emerald-700 dark:text-emerald-300" />
							<Text className="font-medium text-emerald-700 dark:text-emerald-300">Factura disponible</Text>
						</div>
						{invoiceUploadedAt && (
							<Text className="mt-1 text-sm text-emerald-700/90 dark:text-emerald-300/90">
								Subida el <Strong>{invoiceUploadedAt}</Strong>.
							</Text>
						)}
					</div>

					{invoiceRequest && (
						<div className="rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-slate-700 dark:bg-slate-900/50">
							<Text className="text-sm font-medium text-zinc-800 dark:text-slate-200">
								Datos usados para solicitar la factura
							</Text>
							<div className="mt-2 space-y-1 text-sm text-zinc-600 dark:text-slate-300">
								<Text>
									<Strong>Perfil fiscal:</Strong> {invoiceRequest.name} ({invoiceRequest.rfc})
								</Text>
								<Text>
									<Strong>Uso de CFDI:</Strong> {invoiceRequest.formatted_cfdi_use || invoiceRequest.cfdi_use}
								</Text>
								{invoiceRequestedAt && (
									<Text>
										<Strong>Solicitud enviada:</Strong> {invoiceRequestedAt}
									</Text>
								)}
							</div>
						</div>
					)}
				</div>
			)}

			{!hasInvoice && hasInvoiceRequest && (
				<div className="space-y-3 rounded-xl bg-blue-50/70 p-4 dark:bg-blue-950/20">
					<div className="flex items-center gap-2">
						<ClockIcon className="size-5 text-blue-700 dark:text-blue-300" />
						<Text className="font-medium text-blue-700 dark:text-blue-300">Factura solicitada</Text>
					</div>
					<Text className="break-words text-sm text-blue-700/90 dark:text-blue-300/90">
						Tu solicitud esta en proceso. Recibiras la factura por correo en 3 a 5 dias habiles.
					</Text>
					<div className="rounded-lg border border-blue-200/80 bg-white/70 p-3 dark:border-blue-900/70 dark:bg-blue-950/30">
						<div className="space-y-1 text-sm text-blue-800 dark:text-blue-200">
							<Text>
								<Strong>Perfil fiscal usado:</Strong> {invoiceRequest?.name} ({invoiceRequest?.rfc})
							</Text>
							<Text>
								<Strong>Uso de CFDI:</Strong>{" "}
								{invoiceRequest?.formatted_cfdi_use || invoiceRequest?.cfdi_use || "No disponible"}
							</Text>
							{invoiceRequestedAt && (
								<Text>
									<Strong>Solicitada el:</Strong> {invoiceRequestedAt}
								</Text>
							)}
						</div>
					</div>
				</div>
			)}

			{canRequestInvoice && !inlineForm && (
				<div className="space-y-2">
					<Anchor
						href={route("laboratory-purchases.show", {
							laboratory_purchase: purchase,
							tab: "facturas",
						})}
						className="no-underline"
					>
						<Button className="w-full" type="button">
							Solicitar factura
						</Button>
					</Anchor>
					<p className="break-words text-xs text-zinc-500 dark:text-slate-400">
						Te quedan {daysLeftToRequestInvoice} días para solicitar la factura.
					</p>
				</div>
			)}

			{inlineForm && canRequestInvoice && (
				<form onSubmit={submitInvoiceRequest} className="space-y-4">
					<div className="rounded-xl border border-zinc-200 bg-zinc-50/70 p-4 dark:border-slate-700 dark:bg-slate-900/50">
						<div className="mb-3 flex items-start gap-3">
							<ReceiptPercentIcon className="mt-0.5 size-5 text-famedic-dark dark:text-famedic-light" />
							<div className="space-y-1">
								<Text className="font-medium text-zinc-800 dark:text-slate-200">
									Para solicitar tu factura completa los siguientes datos
								</Text>
								<Text className="text-sm text-zinc-600 dark:text-slate-400">
									1) Selecciona tu perfil fiscal. 2) Elige el uso de CFDI. 3) Envía la solicitud.
								</Text>
								<Text className="text-xs text-amber-700 dark:text-amber-300">
									<Strong>Tiempo restante:</Strong> {daysLeftToRequestInvoice} dia
									{daysLeftToRequestInvoice > 1 ? "s" : ""} para solicitar factura.
								</Text>
							</div>
						</div>
					</div>

					{!hasTaxProfiles && (
						<div className="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-900/70 dark:bg-amber-950/30">
							<Text className="text-sm text-amber-800 dark:text-amber-200">
								No tienes perfiles fiscales registrados. Antes de solicitar factura necesitas crear al menos uno.
							</Text>
							<TextLink href={route("tax-profiles.index")} className="mt-2 inline-flex text-sm">
								Ir a perfiles fiscales
							</TextLink>
						</div>
					)}

					{hasTaxProfiles && (
						<>
							<Field>
								<Label>Perfil fiscal *</Label>
								<Listbox
									invalid={!!errors.tax_profile}
									placeholder="Selecciona un perfil fiscal"
									value={data.tax_profile}
									onChange={(value) => setData("tax_profile", value)}
									disabled={processing}
								>
									{taxProfiles.map((profile) => (
										<ListboxOption key={profile.id} value={profile.id}>
											<ListboxLabel className="w-40">
												{profile.rfc}
												<br />
												{profile.name}
											</ListboxLabel>
											<ListboxDescription className="w-40">
												{profile.formatted_tax_regime}
											</ListboxDescription>
										</ListboxOption>
									))}
								</Listbox>
								{errors.tax_profile && <ErrorMessage>{errors.tax_profile}</ErrorMessage>}
							</Field>

							<Field>
								<Label>Uso de CFDI *</Label>
								<Listbox
									invalid={!!errors.cfdi_use}
									placeholder="Selecciona un uso de CFDI"
									value={data.cfdi_use}
									onChange={(value) => setData("cfdi_use", value)}
									disabled={processing}
								>
									{CFDI_OPTIONS.map((option) => (
										<ListboxOption key={option.value} value={option.value}>
											<ListboxLabel className="w-24">{option.label}</ListboxLabel>
											<ListboxDescription className="flex-1">{option.description}</ListboxDescription>
										</ListboxOption>
									))}
								</Listbox>
								{errors.cfdi_use && <ErrorMessage>{errors.cfdi_use}</ErrorMessage>}
							</Field>

							<Button type="submit" disabled={processing} className="w-full sm:w-auto">
								Solicitar factura
								{processing && <ArrowPathIcon className="ml-2 size-4 animate-spin" />}
							</Button>
						</>
					)}
				</form>
			)}

			{!hasInvoice && !hasInvoiceRequest && !canRequestInvoice && (
				<p className="break-words text-sm text-zinc-500 dark:text-slate-400">
					El periodo para solicitar factura ya termino.
				</p>
			)}
		</Card>
	);
}
