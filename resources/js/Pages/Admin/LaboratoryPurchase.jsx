import React, { useState } from "react";
import axios from "axios";
import { useForm } from "@inertiajs/react";

import {
	DocumentTextIcon,
	QrCodeIcon,
	EllipsisHorizontalIcon,
} from "@heroicons/react/16/solid";

import {
	TrashIcon,
	CalendarDaysIcon,
	EnvelopeIcon,
} from "@heroicons/react/24/outline";

import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { Button } from "@/Components/Catalyst/button";

import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";

import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";

import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import PhoneButton from "@/Components/PhoneButton";
import CustomerLink from "@/Components/CustomerLink";
import InvoiceDialog from "@/Components/InvoiceDialog";
import ResultsDialog from "@/Components/ResultsDialog";
import DevAssistanceButton from "@/Components/DevAssistance/DevAssistanceButton";
import DevAssistanceDropdown from "@/Components/DevAssistance/DevAssistanceDropdown";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import PaymentDetails from "@/Components/PaymentDetails";


export default function LaboratoryPurchase({
	laboratoryPurchase,
	showDeleteButton,
	canResendConfirmationEmail,
	hasSampleCollected,
	hasResultsAvailable,
	latestSampleCollectionAt,
	latestResultsAt,
}) {

	return (
		<AdminLayout title="Pedido de laboratorio">

			<Header
				laboratoryPurchase={laboratoryPurchase}
				showDeleteButton={showDeleteButton}
				canResendConfirmationEmail={canResendConfirmationEmail}
				hasSampleCollected={hasSampleCollected}
				hasResultsAvailable={hasResultsAvailable}
				latestSampleCollectionAt={latestSampleCollectionAt}
				latestResultsAt={latestResultsAt}
			/>

			<Patient laboratoryPurchase={laboratoryPurchase} />

			<Order laboratoryPurchase={laboratoryPurchase} />

			<LaboratoryAppointment laboratoryPurchase={laboratoryPurchase} />

			{laboratoryPurchase.transactions.length > 0 && (
				<PaymentDetails transaction={laboratoryPurchase.transactions[0]} />
			)}

		</AdminLayout>
	);
}


function Header({
	laboratoryPurchase,
	showDeleteButton,
	canResendConfirmationEmail,
	hasSampleCollected,
	hasResultsAvailable,
	latestSampleCollectionAt,
	latestResultsAt,
}) {

	const resendForm = useForm({});

	const [loadingResults, setLoadingResults] = useState(false);

	const [debugRequest, setDebugRequest] = useState(null);
	const [debugResponse, setDebugResponse] = useState(null);
	const [debugError, setDebugError] = useState(null);

	const isLocal = import.meta.env.VITE_APP_ENV === "local";

	const fetchResults = async () => {

		const endpoint = route(
			"admin.laboratory-purchases.fetch-results",
			laboratoryPurchase.id
		);

		const payloadDebug = {
			purchase_id: laboratoryPurchase.id,
			gda_consecutivo: laboratoryPurchase.gda_consecutivo,
			gda_order_id: laboratoryPurchase.gda_order_id
		};

		setDebugRequest({
			endpoint,
			payload: payloadDebug
		});

		console.log("REQUEST", payloadDebug);

		try {

			setLoadingResults(true);

			const response = await axios.post(endpoint);

			console.log("RESPONSE", response.data);

			setDebugResponse(response.data);

			const base64 = response.data.pdf_base64;

			if (!base64) {
				alert("No se recibió PDF");
				return;
			}

			const pdfWindow = window.open("");

			pdfWindow.document.write(
				`<iframe width="100%" height="100%" src="data:application/pdf;base64,${base64}"></iframe>`
			);

		} catch (error) {

			console.error("ERROR", error);

			setDebugError(error.response?.data || error.message);

			alert("Error obteniendo resultados del laboratorio");

		} finally {

			setLoadingResults(false);

		}
	};

	return (
		<>

			<LaboratoryBrandCard
				className="w-40"
				src={"/images/gda/GDA-" + laboratoryPurchase.brand.toUpperCase() + ".png"}
			/>

			<div className="flex w-full flex-wrap justify-between gap-4">

				<div className="space-y-4">

					<Text className="flex items-center gap-2 !text-xs">
						<CalendarDaysIcon className="size-5 text-gray-500" />
						{laboratoryPurchase.formatted_created_at}
					</Text>

					<div className="flex flex-wrap items-center gap-4">

						<Heading>Pedido de laboratorio</Heading>

						<Badge color="sky">
							<QrCodeIcon className="size-5" />
							<span className="text-lg">
								{laboratoryPurchase.gda_order_id}
							</span>
						</Badge>

						{/* New badge for gda_consecutivo */}
						{laboratoryPurchase.gda_consecutivo && (
							<Badge color="purple">
								<span className="text-lg">
									{laboratoryPurchase.gda_consecutivo}
								</span>
							</Badge>
						)}

						<Badge color={hasSampleCollected ? "amber" : "slate"}>
							{hasSampleCollected ? "Muestra tomada" : "Pendiente toma"}

							{hasSampleCollected && latestSampleCollectionAt && (
								<span className="ml-2 text-xs opacity-70">
									{latestSampleCollectionAt}
								</span>
							)}
						</Badge>

						<Badge color={hasResultsAvailable ? "emerald" : "slate"}>
							{hasResultsAvailable ? "Resultados disponibles" : "Resultados pendientes"}

							{hasResultsAvailable && latestResultsAt && (
								<span className="ml-2 text-xs opacity-70">
									{latestResultsAt}
								</span>
							)}
						</Badge>

					</div>

				</div>

				{showDeleteButton && (
					<DeleteDialog
						laboratoryPurchase={laboratoryPurchase}
						className="w-full self-end sm:w-auto"
					/>
				)}

			</div>

			<div className="flex flex-wrap gap-4">

				<CustomerLink
					href={route(
						"admin.customers.show",
						laboratoryPurchase.customer.id
					)}
				>
					{laboratoryPurchase.customer.user.full_name}
				</CustomerLink>

				{canResendConfirmationEmail && (
					<div className="flex flex-col gap-1">
						<Button
							outline
							type="button"
							onClick={() =>
								resendForm.post(
									route(
										"admin.laboratory-purchases.resend-confirmation-email",
										{
											laboratory_purchase:
												laboratoryPurchase.id,
										}
									),
									{ preserveScroll: true }
								)
							}
							disabled={resendForm.processing}
						>
							<EnvelopeIcon className="size-5" />
							{resendForm.processing
								? "Enviando correo…"
								: "Reenviar correo de compra"}
						</Button>
						{resendForm.errors.resend_confirmation && (
							<Text className="!text-sm text-red-600">
								{resendForm.errors.resend_confirmation}
							</Text>
						)}
					</div>
				)}

				<InvoiceDialog
					storeRoute={route("admin.laboratory-purchases.invoice", {
						laboratory_purchase: laboratoryPurchase.id,
					})}
					invoiceRoute={
						laboratoryPurchase.invoice
							? route("invoice", {
								invoice: laboratoryPurchase.invoice.id,
							})
							: null
					}
					invoiceRequest={laboratoryPurchase.invoice_request}
					hasInvoice={!!laboratoryPurchase.invoice}
				/>

				<ResultsDialog
					storeRoute={route("admin.laboratory-purchases.results", {
						laboratory_purchase: laboratoryPurchase,
					})}
					resultsRoute={
						laboratoryPurchase.results
							? route("laboratory-purchases.results", {
								laboratory_purchase: laboratoryPurchase,
							})
							: null
					}
					hasResults={!!laboratoryPurchase.results}
				/>

				{/*}
				{hasResultsAvailable && (
					<Button
						color="emerald"
						onClick={fetchResults}
						disabled={loadingResults}
					>
						<DocumentTextIcon />
						{loadingResults
							? "Consultando GDA..."
							: "Consultar resultados GDA"}
					</Button>
				)}
				*/}
				{laboratoryPurchase.dev_assistance_requests.length === 0 ? (
					<DevAssistanceButton
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							}
						)}
					/>
				) : (
					<DevAssistanceDropdown
						requests={laboratoryPurchase.dev_assistance_requests}
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							}
						)}
						resolveRouteName="admin.laboratory-purchases.dev-assistance-request.resolved"
						unresolveRouteName="admin.laboratory-purchases.dev-assistance-request.unresolved"
						routeParams={{
							laboratory_purchase: laboratoryPurchase.id,
						}}
					/>
				)}

			</div>

			{isLocal && (
				<div className="mt-6 p-4 rounded-lg border bg-black text-green-400 text-xs font-mono space-y-4">

					<div className="text-yellow-400">
						GDA DEBUG PANEL
					</div>

					{debugRequest && (
						<div>
							<div className="text-yellow-300">REQUEST</div>
							<pre>
								{JSON.stringify(debugRequest, null, 2)}
							</pre>
						</div>
					)}

					{debugResponse && (
						<div>
							<div className="text-blue-300">RESPONSE</div>
							<pre>
								{JSON.stringify(debugResponse, null, 2)}
							</pre>
						</div>
					)}

					{debugError && (
						<div>
							<div className="text-red-400">ERROR</div>
							<pre>
								{JSON.stringify(debugError, null, 2)}
							</pre>
						</div>
					)}

				</div>
			)}

		</>
	);
}


function Patient({ laboratoryPurchase }) {

	return (

		<div>

			<Subheading>Paciente</Subheading>

			<DescriptionList>

				<DescriptionTerm>Nombre</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.full_name ?? "..."}
				</DescriptionDetails>

				<DescriptionTerm>Sexo</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.formatted_gender}
				</DescriptionDetails>

				<DescriptionTerm>Fecha de nacimiento</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.formatted_birth_date}
				</DescriptionDetails>

				<DescriptionTerm>Teléfono</DescriptionTerm>

				<DescriptionDetails>

					<PhoneButton
						phone={laboratoryPurchase.phone}
						fullPhone={laboratoryPurchase.full_phone}
						countryCode={laboratoryPurchase.phone_country}
					/>

				</DescriptionDetails>

			</DescriptionList>

		</div>

	);

}


function Order({ laboratoryPurchase }) {
	const transaction = laboratoryPurchase.transactions?.[0] ?? null;
	const commissionCentsRaw = transaction?.details?.commission_cents;
	const commissionCents = Number.isFinite(Number(commissionCentsRaw))
		? Number(commissionCentsRaw)
		: null;
	const formattedCommission =
		commissionCents === null
			? null
			: new Intl.NumberFormat("es-MX", {
					style: "currency",
					currency: "MXN",
				}).format(commissionCents / 100);

	return (

		<div>

			<Subheading>Pedido</Subheading>

			<DescriptionList>

				<DescriptionTerm>Estudios</DescriptionTerm>

				<DescriptionDetails>

					<div className="flex flex-col gap-1">

						{laboratoryPurchase.laboratory_purchase_items.map(
							(item) => (

								<span key={item.id}>

									<Badge color="slate">

										{item.name} ({item.formatted_price})

									</Badge>

								</span>

							)
						)}

					</div>

				</DescriptionDetails>

				<DescriptionTerm>Total</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.formatted_total}
				</DescriptionDetails>

				{formattedCommission !== null && (
					<>
						<DescriptionTerm>Comisión</DescriptionTerm>
						<DescriptionDetails>{formattedCommission}</DescriptionDetails>
					</>
				)}

			</DescriptionList>

		</div>

	);

}


function LaboratoryAppointment({ laboratoryPurchase }) {

	if (!laboratoryPurchase.laboratory_appointment) {
		return null;
	}

	return (

		<div>

			<Subheading>Confirmación de cita</Subheading>

			<DescriptionList>

				<DescriptionTerm>Fecha de cita</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.laboratory_appointment.formatted_appointment_date ?? "..."}
				</DescriptionDetails>

				<DescriptionTerm>Sucursal</DescriptionTerm>

				<DescriptionDetails>
					{laboratoryPurchase.laboratory_appointment.laboratory_store?.name ?? "..."}
				</DescriptionDetails>

			</DescriptionList>

		</div>

	);

}


function DeleteDialog({ laboratoryPurchase, className = "" }) {

	const [isOpen, setIsOpen] = useState(false);

	const { delete: destroy, processing } = useForm({});

	const handleDelete = () => {

		if (!processing) {

			destroy(
				route("admin.laboratory-purchases.destroy", {
					laboratory_purchase: laboratoryPurchase,
				})
			);

		}

	};

	return (

		<>

			<Dropdown>

				<DropdownButton outline className={className}>

					Acciones

					<EllipsisHorizontalIcon />

				</DropdownButton>

				<DropdownMenu>

					<DropdownItem onClick={() => setIsOpen(true)}>

						<TrashIcon className="stroke-red-500 dark:stroke-red-400" />

						Cancelar pedido

					</DropdownItem>

				</DropdownMenu>

			</Dropdown>

			<DeleteConfirmationModal
				isOpen={isOpen}
				close={() => setIsOpen(false)}
				title="Cancelar pedido"
				description="¿Estás seguro de cancelar este pedido?"
				processing={processing}
				destroy={handleDelete}
			/>

		</>

	);

}
