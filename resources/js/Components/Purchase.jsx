import { useState } from "react";
import { usePage } from "@inertiajs/react";
import { Anchor, Code, Strong, Text } from "@/Components/Catalyst/text";
import {
	IdentificationIcon,
	MapIcon,
	CreditCardIcon,
	CalendarDaysIcon,
	QrCodeIcon,
	BuildingStorefrontIcon,
	TruckIcon,
} from "@heroicons/react/24/solid";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Divider } from "@/Components/Catalyst/divider";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	MapPinIcon,
	DocumentTextIcon,
	ArrowDownTrayIcon,
	EnvelopeIcon,
	ClockIcon,
} from "@heroicons/react/24/outline";
import RequestInvoiceModal from "@/Components/RequestInvoiceModal";
import CreditCardBrand from "@/Components/CreditCardBrand";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import PurchasePdfDialog from "@/Components/PurchasePdfDialog";
import Card from "@/Components/Card";

export default function Purchase({ purchase, isLabPurchase = false }) {
	const [showRequestInvoiceModal, setShowRequestInvoiceModal] =
		useState(false);

	const { daysLeftToRequestInvoice } = usePage().props;

	return (
		<>
			<Header isLabPurchase={isLabPurchase} purchase={purchase} />

			{isLabPurchase && (
				<InvoiceRequestWarningBanner
					daysLeftToRequestInvoice={daysLeftToRequestInvoice}
					purchase={purchase}
				/>
			)}

			{/* Invoice and Results Actions */}
			{((!purchase.invoice &&
				(purchase.invoice_request || daysLeftToRequestInvoice > 0)) ||
				purchase.invoice ||
				purchase.results) && (
				<div className="flex flex-col gap-3 sm:flex-row sm:justify-start">
					{/* Invoice Button */}
					{!purchase.invoice && daysLeftToRequestInvoice > 0 && (
						<Button
							outline
							onClick={() => setShowRequestInvoiceModal(true)}
							className="w-full sm:w-auto"
						>
							{purchase.invoice_request ? (
								<ClockIcon />
							) : (
								<DocumentTextIcon />
							)}
							{purchase.invoice_request
								? "Factura solicitada"
								: `Solicitar factura (${daysLeftToRequestInvoice} día${daysLeftToRequestInvoice > 1 ? "s" : ""})`}
						</Button>
					)}

					{purchase.invoice && (
						<Anchor
							href={route("invoice", {
								invoice: purchase.invoice,
							})}
							target="_blank"
							rel="noopener noreferrer"
						>
							<Button outline className="w-full">
								<DocumentTextIcon />
								Ver factura
							</Button>
						</Anchor>
					)}

					{/* Results Button */}
					{purchase.results && (
						<Anchor
							href={route("laboratory-purchases.results", {
								laboratory_purchase: purchase,
							})}
							target="_blank"
							rel="noopener noreferrer"
							className="w-full sm:w-auto"
						>
							<Button outline className="w-full">
								<DocumentTextIcon />
								Ver resultados
							</Button>
						</Anchor>
					)}
				</div>
			)}

			<PurchaseDetails
				purchase={purchase}
				isLabPurchase={isLabPurchase}
			/>

			<Items purchase={purchase} isLabPurchase={isLabPurchase} />

			<Totals purchase={purchase} isLabPurchase={isLabPurchase} />

			<InvoiceModal
				purchase={purchase}
				isLabPurchase={isLabPurchase}
				showRequestInvoiceModal={showRequestInvoiceModal}
				setShowRequestInvoiceModal={setShowRequestInvoiceModal}
				daysLeftToRequestInvoice={daysLeftToRequestInvoice}
			/>
		</>
	);
}

function Header({ purchase, isLabPurchase }) {
	const [showPdfDialog, setShowPdfDialog] = useState(false);
	const [pdfDialogTab, setPdfDialogTab] = useState(0);

	return (
		<>
			<div className="flex items-center justify-between gap-4">
				<Text className="flex items-center gap-2 !text-xs">
					<CalendarDaysIcon className="size-5 fill-zinc-500 dark:fill-slate-400" />
					{purchase.formatted_created_at}
				</Text>

				{/* Share/Download Actions */}
				{isLabPurchase && (
					<div className="flex gap-2">
						<Button
							outline
							onClick={() => {
								setPdfDialogTab(0);
								setShowPdfDialog(true);
							}}
							title="Obtener PDF"
						>
							<ArrowDownTrayIcon className="size-5 !stroke-slate-950 dark:!stroke-white" />
						</Button>
						<Button
							outline
							onClick={() => {
								setPdfDialogTab(1);
								setShowPdfDialog(true);
							}}
							title="Compartir"
						>
							<EnvelopeIcon className="size-5 !stroke-slate-950 dark:!stroke-white" />
						</Button>
					</div>
				)}
			</div>

			<div className="gap-6 max-md:space-y-6 md:flex md:items-center">
				<GradientHeading noDivider className="flex-1">
					!Gracias por tu pedido!
				</GradientHeading>

				{isLabPurchase && (
					<LaboratoryBrandCard
						src={
							"/images/gda/GDA-" +
							purchase.brand.toUpperCase() +
							".png"
						}
						className="w-64"
					/>
				)}
			</div>

			<div className="flex justify-start">
				<div className="flex flex-col gap-2">
					<Subheading className="flex gap-2">Folio</Subheading>

					<Badge color="famedic" className="w-min !text-4xl">
						<QrCodeIcon className="size-10" />
						{isLabPurchase
							? purchase.gda_order_id
							: purchase.vitau_order_id}
					</Badge>
				</div>
			</div>

			{/* PDF Dialog */}
			{isLabPurchase && (
				<PurchasePdfDialog
					laboratoryPurchase={purchase}
					isOpen={showPdfDialog}
					onClose={setShowPdfDialog}
					selectedTab={pdfDialogTab}
					setSelectedTab={setPdfDialogTab}
				/>
			)}
		</>
	);
}

function PurchaseDetails({ purchase, isLabPurchase }) {
	return (
		<div className="xl:mr-40 xl:pr-6">
			<div className="grid gap-8 py-8 sm:grid-cols-2 lg:gap-10 lg:py-10">
				<Patient isLabPurchase={isLabPurchase} purchase={purchase} />

				<Divider className="sm:hidden" />

				{isLabPurchase &&
					(purchase.laboratory_appointment ? (
						<LaboratoryAppointment
							laboratoryAppointment={
								purchase.laboratory_appointment
							}
						/>
					) : (
						<LaboratoryStores purchase={purchase} />
					))}

				{!isLabPurchase && <PharmacyDelivery purchase={purchase} />}
			</div>

			<Divider />

			<div className="grid gap-8 py-8 sm:grid-cols-2 lg:gap-10 lg:py-10">
				<PaymentMethod purchase={purchase} />
				<Divider className="sm:hidden" />
				<Address purchase={purchase} />
			</div>
		</div>
	);
}

function Items({ purchase, isLabPurchase }) {
	const purchaseItems = isLabPurchase
		? purchase.laboratory_purchase_items
		: purchase.online_pharmacy_purchase_items;
	return (
		<div>
			<Divider className="hidden md:block" />
			{purchaseItems.map((purchaseItem) => (
				<div key={purchaseItem.id}>
					<div className="flex space-x-6 py-10">
						<div className="flex flex-auto flex-col">
							<div>
								<Subheading>
									{!isLabPurchase && (
										<Badge color="slate" className="mr-2">
											{purchaseItem.quantity}
										</Badge>
									)}
									{purchaseItem.name}
								</Subheading>
								{((isLabPurchase && purchaseItem.indications) ||
									(!isLabPurchase &&
										purchaseItem.presentation)) && (
									<Text className="mt-2 max-w-xl">
										{isLabPurchase
											? purchaseItem.indications
											: purchaseItem.presentation}
									</Text>
								)}
							</div>
							<div className="mt-6 flex flex-1 items-end">
								<div className="text-sm sm:flex sm:flex-wrap sm:gap-x-6">
									<div className="flex">
										<Subheading>Precio</Subheading>
										<Text className="ml-2">
											{purchaseItem.formatted_price}
										</Text>
									</div>
									{!isLabPurchase && (
										<>
											{purchaseItem.tax_cents !== 0 && (
												<div className="flex sm:pl-6">
													<Subheading>
														Impuesto
													</Subheading>
													<Text className="ml-2">
														{
															purchaseItem.formatted_tax
														}
													</Text>
												</div>
											)}
											{purchaseItem.discount_cents !==
												0 && (
												<div className="flex sm:pl-6">
													<Subheading>
														Descuento
													</Subheading>
													<Text className="ml-2">
														-
														{
															purchaseItem.formatted_discount
														}
													</Text>
												</div>
											)}
											<div className="flex sm:pl-6">
												<Subheading>Total</Subheading>
												<Text className="ml-2">
													{
														purchaseItem.formatted_total
													}
												</Text>
											</div>
										</>
									)}
								</div>
							</div>
						</div>
					</div>
					<Divider></Divider>
				</div>
			))}
		</div>
	);
}

function Patient({ purchase, isLabPurchase }) {
	return (
		<div>
			<PurchaseLabel icon={IdentificationIcon}>
				{isLabPurchase ? "Paciente" : "Quien recibe"}
			</PurchaseLabel>
			<div className="mt-4">
				{isLabPurchase ? (
					<Text>
						{purchase.temporarly_hide_gda_order_id
							? "Nombre de paciente pendiente"
							: purchase.full_name}
					</Text>
				) : (
					<Text>{purchase.full_name}</Text>
				)}
				<Text>{purchase.phone}</Text>
				{isLabPurchase && (
					<>
						<Text>{purchase.formatted_birth_date}</Text>
						<Text>{purchase.formatted_gender}</Text>
					</>
				)}
			</div>
		</div>
	);
}

function PaymentMethod({ purchase }) {
	let hasNoTransactions =
		!purchase.transactions || purchase.transactions.length === 0;

	return (
		<div>
			<PurchaseLabel icon={CreditCardIcon}>Método de pago</PurchaseLabel>
			<div className="mt-4">
				{hasNoTransactions && <Text>No registrado</Text>}

				{!hasNoTransactions &&
					(purchase.transactions[0].payment_method === "odessa" ? (
						<div className="flex gap-1">
							<img
								src="/images/odessa.png"
								alt="odessa"
								className="h-6 w-6"
							/>
							<div>
								<Text>ODESSA</Text>

								<p className="-mt-1 text-xs text-orange-600 dark:text-orange-400">
									Cobro a caja de ahorro
								</p>
							</div>
						</div>
					) : (
						<div className="flex items-center gap-2">
							<CreditCardBrand
								brand={
									purchase.transactions[0].details.card_brand
								}
							/>
							<Code>
								{
									purchase.transactions[0].details
										.card_last_four
								}
							</Code>
						</div>
					))}
			</div>
		</div>
	);
}

function Address({ purchase }) {
	return (
		<div>
			<PurchaseLabel icon={MapIcon}>Dirección</PurchaseLabel>
			<div className="mt-4">
				<div>
					<Text>
						{purchase.street} {purchase.number}
					</Text>
					<Text>
						{purchase.neighborhood}, {purchase.zipcode}
					</Text>
					<Text>
						{purchase.city}, {purchase.state}
					</Text>
				</div>
				<Text className="mt-4">
					<span className="text-xs">
						{purchase.additional_references}
					</span>
				</Text>
			</div>
		</div>
	);
}

function LaboratoryAppointment({ laboratoryAppointment }) {
	return (
		<div>
			<PurchaseLabel icon={CalendarDaysIcon}>Cita</PurchaseLabel>
			<div className="mt-4 space-y-2">
				<Text>{laboratoryAppointment.laboratory_store?.name}</Text>
				<Badge color="sky">
					{laboratoryAppointment.formatted_appointment_date}
				</Badge>
				<Text className="max-w-64">
					{laboratoryAppointment.laboratory_store?.address}
				</Text>
				<a
					target="_blank"
					href={
						laboratoryAppointment.laboratory_store?.google_maps_url
					}
				>
					<Button outline className="mt-1">
						<MapPinIcon className="size-6" />
						Ver en mapa
					</Button>
				</a>
				<Text>
					<span className="text-xs">
						{laboratoryAppointment.notes}
					</span>
				</Text>
			</div>
		</div>
	);
}

function LaboratoryStores({ purchase }) {
	return (
		<div>
			<PurchaseLabel icon={BuildingStorefrontIcon}>
				Sucursales
			</PurchaseLabel>
			<div className="mt-4">
				<Text className="max-w-64">
					Puedes acudir a cualquiera de las sucursales de la marca a
					realizarte tus estudios
				</Text>
				<Button
					href={route("laboratory-stores.index", {
						brand: purchase.brand,
					})}
					outline
					className="mt-1"
				>
					<MapPinIcon className="size-4" />
					Ver sucursales{" "}
				</Button>
			</div>
		</div>
	);
}

function PharmacyDelivery({ purchase }) {
	return (
		<div>
			<PurchaseLabel icon={TruckIcon}>Entrega estimada</PurchaseLabel>

			<div className="mt-4">
				<Text className="max-w-64">
					{purchase.formatted_expected_delivery_date}
				</Text>
			</div>
		</div>
	);
}

function Totals({ purchase, isLabPurchase }) {
	return (
		<div className="space-y-6 text-sm">
			{!isLabPurchase && (
				<>
					<div className="flex justify-between">
						<Text>Subtotal</Text>

						<Text>{purchase.formatted_subtotal}</Text>
					</div>
					<div className="flex justify-between">
						<Text>Envío</Text>

						<Text>{purchase.formatted_shipping_price}</Text>
					</div>
					{purchase.tax_cents !== 0 && (
						<div className="flex justify-between">
							<Text>Impuesto</Text>

							<Text>{purchase.formatted_tax}</Text>
						</div>
					)}
					{purchase.discount_cents !== 0 && (
						<div className="flex justify-between">
							<Text>Descuento</Text>

							<Text>-{purchase.formatted_discount}</Text>
						</div>
					)}
				</>
			)}
			<div className="flex justify-between">
				<Subheading>Total</Subheading>

				<Subheading>{purchase.formatted_total}</Subheading>
			</div>
		</div>
	);
}

function InvoiceModal({
	purchase,
	isLabPurchase,
	showRequestInvoiceModal,
	setShowRequestInvoiceModal,
	daysLeftToRequestInvoice,
}) {
	if (!daysLeftToRequestInvoice) return null;

	return (
		<RequestInvoiceModal
			purchase={purchase}
			isOpen={showRequestInvoiceModal}
			storeRoute={route(
				isLabPurchase
					? "laboratory-purchases.invoice-request"
					: "online-pharmacy-purchases.invoice-request",
				{
					...(isLabPurchase
						? {
								laboratory_purchase: purchase,
							}
						: {
								online_pharmacy_purchase: purchase,
							}),
				},
			)}
			close={() => setShowRequestInvoiceModal(false)}
		/>
	);
}

function PurchaseLabel({ icon: Icon, children }) {
	return (
		<Subheading className="flex gap-2">
			<Icon className="size-6 fill-zinc-300 dark:fill-slate-600" />
			{children}
		</Subheading>
	);
}

function InvoiceRequestWarningBanner({ daysLeftToRequestInvoice, purchase }) {
	if (
		daysLeftToRequestInvoice <= 0 ||
		daysLeftToRequestInvoice >= 8 ||
		purchase.invoice ||
		purchase.invoice_request
	) {
		return null;
	}

	return (
		<Card className="p-6">
			<div className="flex items-center gap-3">
				<ClockIcon className="size-6 flex-shrink-0 text-slate-500" />
				<Text>
					<Strong>¡Tiempo limitado para solicitar factura!</Strong>
				</Text>
			</div>
			<Text className="mt-3">
				Solo tienes{" "}
				<Strong>
					{daysLeftToRequestInvoice} día
					{daysLeftToRequestInvoice > 1 ? "s" : ""} restante
					{daysLeftToRequestInvoice > 1 ? "s" : ""}
				</Strong>{" "}
				para solicitar la factura de esta orden. Las facturas solo
				pueden solicitarse hasta el último día del mes.
			</Text>
		</Card>
	);
}

