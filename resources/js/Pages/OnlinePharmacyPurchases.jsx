export default function OnlinePharmacyPurchases({ onlinePharmacyPurchases }) {
	return (
		<SettingsLayout title="Mis pedidos">
			<GradientHeading>Mis pedidos</GradientHeading>

			<Navbar className="-mt-6 mb-10">
				<NavbarItem
					href={route("laboratory-purchases.index")}
					current={route().current("laboratory-purchases.index")}
				>
					Laboratorios
				</NavbarItem>
				<NavbarItem
					href={route("online-pharmacy-purchases.index")}
					current={route().current("online-pharmacy-purchases.index")}
				>
					Farmacia
				</NavbarItem>
			</Navbar>

			<OnlinePharmacyPurchasesList
				onlinePharmacyPurchases={onlinePharmacyPurchases}
			/>
		</SettingsLayout>
	);
}

function OnlinePharmacyPurchasesList({ onlinePharmacyPurchases }) {
	if (onlinePharmacyPurchases.length === 0)
		return (
			<EmptyListCard
				heading="No tienes pedidos"
				message="Puedes hacer pedidos de laboratorios y farmacia en línea desde el menú principal."
			/>
		);

	return (
		<div className="mb-20 space-y-20">
			{onlinePharmacyPurchases.map((onlinePharmacyPurchase) => (
				<PurchaseCard
					href={route("online-pharmacy-purchases.show", {
						online_pharmacy_purchase: onlinePharmacyPurchase,
					})}
					key={onlinePharmacyPurchase.id}
					cardContent={
						<>
							<div className="flex flex-col items-center gap-4 sm:flex-row">
								<div className="space-y-2 text-center sm:text-left">
									<Text>
										<Strong>
											{onlinePharmacyPurchase.full_name}
										</Strong>
									</Text>

									<div className="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
										<Text>
											{
												onlinePharmacyPurchase.formatted_total
											}
										</Text>

										{onlinePharmacyPurchase.transactions &&
											onlinePharmacyPurchase.transactions
												.length > 0 && (
												<PaymentMethodBadge
													transaction={
														onlinePharmacyPurchase
															.transactions[0]
													}
												/>
											)}
									</div>

									<div className="flex flex-col gap-2">
										<Badge color="slate">
											{onlinePharmacyPurchase.invoice ? (
												<>
													<DocumentTextIcon className="size-4" />
													Factura generada
												</>
											) : onlinePharmacyPurchase.invoice_request ? (
												<>
													<ClockIcon className="size-4" />
													Factura solicitada
												</>
											) : (
												<>
													<DocumentTextIcon className="size-4" />
													Factura no solicitada
												</>
											)}
										</Badge>

										<Badge color="slate">
											{onlinePharmacyPurchase.results ? (
												<>
													<DocumentTextIcon className="size-4" />
													Resultados cargados
												</>
											) : (
												<>
													<ClockIcon className="size-4" />
													Resultados pendientes
												</>
											)}
										</Badge>
									</div>
								</div>
							</div>
							<div className="flex flex-col items-center space-y-2 sm:items-end">
								<Text>
									{
										onlinePharmacyPurchase.formatted_created_at
									}
								</Text>
								<Badge>
									<QrCodeIcon className="size-6" />
									<span className="text-xl">
										{onlinePharmacyPurchase.vitau_order_id}
									</span>
								</Badge>
								<Subheading className="flex items-center group-hover:underline">
									Ver detalle
									<ArrowRightIcon className="ml-1 size-5 transform transition-transform group-hover:translate-x-1 group-hover:scale-125" />
								</Subheading>
							</div>
						</>
					}
					tableHeaders={
						<>
							<TableHeader>Producto</TableHeader>
							<TableHeader>Código</TableHeader>
							<TableHeader className="text-right">
								Precio
							</TableHeader>
						</>
					}
					tableRows={
						<>
							{onlinePharmacyPurchase.online_pharmacy_purchase_items.map(
								(onlinePharmacyPurchaseItem) => (
									<TableRow
										key={onlinePharmacyPurchaseItem.id}
									>
										<TableCell>
											{onlinePharmacyPurchaseItem.name}{" "}
											<Badge color="slate">
												{
													onlinePharmacyPurchaseItem.quantity
												}
											</Badge>
										</TableCell>

										<TableCell>
											{
												onlinePharmacyPurchaseItem.vitau_product_id
											}
										</TableCell>
										<TableCell className="text-right">
											{
												onlinePharmacyPurchaseItem.formatted_price
											}
										</TableCell>
									</TableRow>
								),
							)}
						</>
					}
				/>
			))}
		</div>
	);
}
import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Code, Text } from "@/Components/Catalyst/text";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import { DocumentTextIcon, ClockIcon } from "@heroicons/react/16/solid";
import { Strong } from "@/Components/Catalyst/text";
import { TableCell, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import { QrCodeIcon } from "@heroicons/react/24/solid";
import EmptyListCard from "@/Components/EmptyListCard";
import { Badge } from "@/Components/Catalyst/badge";
import PurchaseCard from "@/Components/PurchaseCard";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
