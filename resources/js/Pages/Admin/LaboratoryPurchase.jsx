import React from "react";
import { useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	DocumentTextIcon,
	QrCodeIcon,
	EllipsisHorizontalIcon,
} from "@heroicons/react/16/solid";
import { TrashIcon } from "@heroicons/react/24/outline";
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
import { CalendarDaysIcon } from "@heroicons/react/24/solid";

export default function LaboratoryPurchase({
	laboratoryPurchase,
	showDeleteButton,
}) {
	return (
		<AdminLayout title="Pedido de laboratorio">
			<Header
				laboratoryPurchase={laboratoryPurchase}
				showDeleteButton={showDeleteButton}
			/>

			<Patient laboratoryPurchase={laboratoryPurchase} />

			<Order laboratoryPurchase={laboratoryPurchase} />

			<LaboratoryAppointment laboratoryPurchase={laboratoryPurchase} />

			{laboratoryPurchase.transactions.length > 0 && (
				<PaymentDetails
					transaction={laboratoryPurchase.transactions[0]}
				/>
			)}

			{laboratoryPurchase.vendor_payments.length > 0 && (
				<VendorPayment laboratoryPurchase={laboratoryPurchase} />
			)}
		</AdminLayout>
	);
}

function Header({ laboratoryPurchase, showDeleteButton }) {
	return (
		<>
			<LaboratoryBrandCard
				className="w-40"
				src={
					"/images/gda/GDA-" +
					laboratoryPurchase.brand.toUpperCase() +
					".png"
				}
			/>

			<div className="flex w-full flex-wrap justify-between gap-4">
				<div className="space-y-4">
					<Text className="flex items-center gap-2 !text-xs">
						<CalendarDaysIcon className="size-5 fill-zinc-500 dark:fill-slate-400" />
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
						laboratoryPurchase.customer.id,
					)}
				>
					{laboratoryPurchase.customer.user.full_name}
				</CustomerLink>
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
				{laboratoryPurchase.dev_assistance_requests.length === 0 ? (
					<DevAssistanceButton
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							},
						)}
					/>
				) : (
					<DevAssistanceDropdown
						requests={laboratoryPurchase.dev_assistance_requests}
						storeRoute={route(
							"admin.laboratory-purchases.dev-assistance-request.store",
							{
								laboratory_purchase: laboratoryPurchase.id,
							},
						)}
						resolveRouteName="admin.laboratory-purchases.dev-assistance-request.resolved"
						unresolveRouteName="admin.laboratory-purchases.dev-assistance-request.unresolved"
						routeParams={{
							laboratory_purchase: laboratoryPurchase.id,
						}}
					/>
				)}
			</div>
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
	return (
		<div>
			<Subheading>Pedido</Subheading>

			<DescriptionList>
				<DescriptionTerm>Estudios </DescriptionTerm>
				<DescriptionDetails>
					<div className="flex flex-col gap-1">
						{laboratoryPurchase.laboratory_purchase_items.map(
							(laboratoryPurchaseItem) => (
								<span key={laboratoryPurchaseItem.id}>
									<Badge color="slate">
										{laboratoryPurchaseItem.name} (
										{laboratoryPurchaseItem.formatted_price}
										)
									</Badge>
								</span>
							),
						)}
					</div>
				</DescriptionDetails>
				<DescriptionTerm>Total</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryPurchase.formatted_total}
				</DescriptionDetails>
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
					{laboratoryPurchase.laboratory_appointment
						.formatted_appointment_date ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Sucursal</DescriptionTerm>
				<DescriptionDetails>
					{laboratoryPurchase.laboratory_appointment.laboratory_store
						?.name ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Dirección</DescriptionTerm>
				<DescriptionDetails>
					<span className="block max-w-48">
						{laboratoryPurchase.laboratory_appointment
							.laboratory_store?.address ?? "..."}
					</span>
				</DescriptionDetails>
				<DescriptionTerm>
					Notas compartidas con el cliente
				</DescriptionTerm>
				<DescriptionDetails>
					<span className="block max-w-80">
						{laboratoryPurchase.laboratory_appointment.notes
							? laboratoryPurchase.laboratory_appointment.notes
							: "..."}
					</span>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function VendorPayment({ laboratoryPurchase }) {
	return (
		<div>
			<Subheading>Pago a proveedor</Subheading>

			<DescriptionList>
				{laboratoryPurchase.vendor_payments.map((vendorPayment) => (
					<React.Fragment key={vendorPayment.id}>
						<DescriptionTerm>
							{vendorPayment.formatted_paid_at}
						</DescriptionTerm>
						<DescriptionDetails>
							<Button
								href={route(
									"admin.laboratory-purchases.vendor-payments.show",
									vendorPayment.id,
								)}
								outline
							>
								<DocumentTextIcon />
								Ver pago
							</Button>
						</DescriptionDetails>
					</React.Fragment>
				))}
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
				}),
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
				title="Ca
				
				ncelar pedido"
				description="¿Estás seguro de que deseas cancelar este pedido? Se reembolsará el total pagado y se mandará un correo al cliente."
				processing={processing}
				destroy={handleDelete}
			/>
		</>
	);
}
