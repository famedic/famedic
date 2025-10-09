import React from "react";
import AdminLayout from "@/Layouts/AdminLayout";
import {
	Dropdown,
	DropdownButton,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import DeleteConfirmationModal from "@/Components/DeleteConfirmationModal";
import {
	DescriptionList,
	DescriptionTerm,
	DescriptionDetails,
} from "@/Components/Catalyst/description-list";
import {
	CalendarIcon,
	UserCircleIcon,
	QrCodeIcon,
	DocumentTextIcon,
} from "@heroicons/react/16/solid";
import { TrashIcon } from "@heroicons/react/24/outline";
import { useForm } from "@inertiajs/react";
import { useState } from "react";
import PhoneButton from "@/Components/PhoneButton";
import CustomerLink from "@/Components/CustomerLink";
import InvoiceDialog from "@/Components/InvoiceDialog";
import DevAssistanceButton from "@/Components/DevAssistance/DevAssistanceButton";
import DevAssistanceDropdown from "@/Components/DevAssistance/DevAssistanceDropdown";
import PaymentDetails from "@/Components/PaymentDetails";
import { EllipsisHorizontalIcon } from "@heroicons/react/24/solid";

export default function OnlinePharmacyPurchase({ onlinePharmacyPurchase }) {
	const [openDeleteConfirmation, setOpenDeleteConfirmation] = useState(false);

	return (
		<AdminLayout title="Pedido de farmacia">
			<Header
				onlinePharmacyPurchase={onlinePharmacyPurchase}
				setOpenDeleteConfirmation={setOpenDeleteConfirmation}
			/>

			<Patient onlinePharmacyPurchase={onlinePharmacyPurchase} />

			<Order onlinePharmacyPurchase={onlinePharmacyPurchase} />

			{onlinePharmacyPurchase.transactions.length > 0 && (
				<PaymentDetails
					transaction={onlinePharmacyPurchase.transactions[0]}
				/>
			)}

			{onlinePharmacyPurchase.vendor_payments.length > 0 && (
				<VendorPayment
					onlinePharmacyPurchase={onlinePharmacyPurchase}
				/>
			)}

			{!onlinePharmacyPurchase.deleted_at && (
				<OnlinePharmacyPurchaseDeleteForm
					onlinePharmacyPurchase={onlinePharmacyPurchase}
					setOpenDeleteConfirmation={setOpenDeleteConfirmation}
					openDeleteConfirmation={openDeleteConfirmation}
				/>
			)}
		</AdminLayout>
	);
}

function Header({ onlinePharmacyPurchase, setOpenDeleteConfirmation }) {
	return (
		<>
			<div className="flex w-full flex-wrap justify-between gap-4">
				<div className="flex flex-wrap items-center gap-4">
					<Heading>Pedido de farmacia</Heading>
					<Badge color="sky">
						<QrCodeIcon className="size-5" />
						<span className="text-lg">
							{onlinePharmacyPurchase.vitau_order_id}
						</span>
					</Badge>
				</div>
				<Dropdown>
					<DropdownButton outline>
						Acciones
						<EllipsisHorizontalIcon />
					</DropdownButton>
					<DropdownMenu>
						<DropdownItem href="/users/1/edit">
							<UserCircleIcon />
							Ver perfil de usuario
						</DropdownItem>
						{!onlinePharmacyPurchase.deleted_at && (
							<DropdownItem
								onClick={() => setOpenDeleteConfirmation(true)}
							>
								<TrashIcon />
								Eliminar
							</DropdownItem>
						)}
					</DropdownMenu>
				</Dropdown>
			</div>

			<div className="isolate flex flex-wrap justify-between gap-x-6 gap-y-4">
				<div className="flex flex-wrap gap-x-10 gap-y-4 py-1.5">
					<span className="flex items-center gap-3 text-base/6 text-zinc-950 sm:text-sm/6 dark:text-white">
						<CalendarIcon className="size-4 shrink-0 fill-zinc-400 dark:fill-zinc-500" />
						<span>
							{onlinePharmacyPurchase.formatted_created_at}
						</span>
					</span>
					<CustomerLink
						href={route(
							"admin.customers.show",
							onlinePharmacyPurchase.customer.id,
						)}
					>
						{onlinePharmacyPurchase.customer.user.full_name}
					</CustomerLink>
					<InvoiceDialog
						storeRoute={route(
							"admin.online-pharmacy-purchases.invoice",
							{
								online_pharmacy_purchase:
									onlinePharmacyPurchase.id,
							},
						)}
						invoiceRoute={
							onlinePharmacyPurchase.invoice
								? route("invoice", {
										invoice: onlinePharmacyPurchase.invoice,
									})
								: null
						}
						invoiceRequest={onlinePharmacyPurchase.invoice_request}
						hasInvoice={!!onlinePharmacyPurchase.invoice}
					/>
					{onlinePharmacyPurchase.dev_assistance_requests.length ===
					0 ? (
						<DevAssistanceButton
							storeRoute={route(
								"admin.online-pharmacy-purchases.dev-assistance-request.store",
								{
									online_pharmacy_purchase:
										onlinePharmacyPurchase.id,
								},
							)}
						/>
					) : (
						<DevAssistanceDropdown
							requests={
								onlinePharmacyPurchase.dev_assistance_requests
							}
							storeRoute={route(
								"admin.online-pharmacy-purchases.dev-assistance-request.store",
								{
									online_pharmacy_purchase:
										onlinePharmacyPurchase.id,
								},
							)}
							resolveRouteName="admin.online-pharmacy-purchases.dev-assistance-request.resolved"
							unresolveRouteName="admin.online-pharmacy-purchases.dev-assistance-request.unresolved"
							routeParams={{
								online_pharmacy_purchase:
									onlinePharmacyPurchase.id,
							}}
						/>
					)}
				</div>
			</div>
		</>
	);
}

function Patient({ onlinePharmacyPurchase }) {
	return (
		<div>
			<Subheading>Paciente</Subheading>

			<DescriptionList>
				<DescriptionTerm>Nombre</DescriptionTerm>
				<DescriptionDetails>
					{onlinePharmacyPurchase.full_name ?? "..."}
				</DescriptionDetails>
				<DescriptionTerm>Teléfono</DescriptionTerm>
				<DescriptionDetails>
					<PhoneButton
						phone={onlinePharmacyPurchase.phone}
						fullPhone={onlinePharmacyPurchase.full_phone}
						countryCode={onlinePharmacyPurchase.phone_country}
					/>
				</DescriptionDetails>
			</DescriptionList>
		</div>
	);
}

function Order({ onlinePharmacyPurchase }) {
	return (
		<div>
			<Subheading>Pedido</Subheading>

			<DescriptionList>
				<DescriptionTerm>Productos </DescriptionTerm>
				<DescriptionDetails>
					<div className="flex flex-col gap-1">
						{onlinePharmacyPurchase.online_pharmacy_purchase_items.map(
							(item) => (
								<span key={item.id}>
									<Badge color="slate">
										({item.quantity}) {item.name} (
										{item.formatted_price})
									</Badge>
								</span>
							),
						)}
					</div>
				</DescriptionDetails>
				<DescriptionTerm>Envío</DescriptionTerm>
				<DescriptionDetails>
					{onlinePharmacyPurchase.formatted_shipping_price}
				</DescriptionDetails>
				<DescriptionTerm>Impuesto</DescriptionTerm>
				<DescriptionDetails>
					{onlinePharmacyPurchase.formatted_tax}
				</DescriptionDetails>
				<DescriptionTerm>Total</DescriptionTerm>
				<DescriptionDetails>
					{onlinePharmacyPurchase.formatted_total}
				</DescriptionDetails>
				{onlinePharmacyPurchase.formatted_expected_delivery_date && (
					<>
						<DescriptionTerm>Entrega estimada</DescriptionTerm>
						<DescriptionDetails>
							{
								onlinePharmacyPurchase.formatted_expected_delivery_date
							}
						</DescriptionDetails>
					</>
				)}
			</DescriptionList>
		</div>
	);
}

function VendorPayment({ onlinePharmacyPurchase }) {
	return (
		<div>
			<Subheading>Pago a proveedor</Subheading>

			<DescriptionList>
				{onlinePharmacyPurchase.vendor_payments.map((vendorPayment) => (
					<React.Fragment key={vendorPayment.id}>
						<DescriptionTerm>
							{vendorPayment.formatted_paid_at}
						</DescriptionTerm>
						<DescriptionDetails>
							<Button
								href={route(
									"admin.online-pharmacy-purchases.vendor-payments.show",
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

function OnlinePharmacyPurchaseDeleteForm({
	onlinePharmacyPurchase,
	setOpenDeleteConfirmation,
	openDeleteConfirmation,
}) {
	const { delete: destroy, processing } = useForm({});

	const deleteOnlinePharmacyPurchase = () => {
		if (!processing) {
			destroy(
				route("admin.online-pharmacy-purchases.destroy", {
					online_pharmacy_purchase: onlinePharmacyPurchase,
				}),
			);
		}
	};

	return (
		<DeleteConfirmationModal
			isOpen={!!openDeleteConfirmation}
			close={() => setOpenDeleteConfirmation(false)}
			title="Eliminar pedido"
			description="¿Estás seguro de que deseas eliminar este pedido? Se reembolsará el total pagado y se mandará un correo al cliente."
			processing={processing}
			destroy={deleteOnlinePharmacyPurchase}
		/>
	);
}
