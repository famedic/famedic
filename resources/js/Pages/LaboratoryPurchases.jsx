import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import {
	DocumentTextIcon,
	ClockIcon,
	MagnifyingGlassIcon,
} from "@heroicons/react/16/solid";
import { TableCell, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import { QrCodeIcon } from "@heroicons/react/24/solid";
import EmptyListCard from "@/Components/EmptyListCard";
import { Badge } from "@/Components/Catalyst/badge";
import PurchaseCard from "@/Components/PurchaseCard";
import PaymentMethodBadge from "@/Components/PaymentMethodBadge";
import { useForm, usePage } from "@inertiajs/react";
import { Input, InputGroup } from "@/Components/Catalyst/input";

export default function LaboratoryPurchases({ laboratoryPurchases }) {
	const { filters } = usePage().props;

	const { data, setData, get, processing } = useForm({
		search: filters?.search || "",
	});

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing) {
			get(route("laboratory-purchases.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

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

			<form className="mb-10" onSubmit={updateResults}>
				<div className="md:max-w-md">
					<InputGroup>
						<MagnifyingGlassIcon />
						<Input
							placeholder="Buscar pedidos"
							value={data.search}
							onChange={(e) => setData("search", e.target.value)}
						/>
					</InputGroup>
				</div>
			</form>

			<LaboratoryPurchasesList
				laboratoryPurchases={laboratoryPurchases}
			/>
		</SettingsLayout>
	);
}

function LaboratoryPurchasesList({ laboratoryPurchases }) {
	if (laboratoryPurchases.length === 0)
		return (
			<EmptyListCard
				heading="No tienes pedidos"
				message="Puedes hacer pedidos de laboratorios y farmacia en línea desde el menú principal."
			/>
		);

	return (
		<div className="mb-20 space-y-20">
			{laboratoryPurchases.map((laboratoryPurchase) => (
				<PurchaseCard
					key={laboratoryPurchase.id}
					href={route("laboratory-purchases.show", {
						laboratory_purchase: laboratoryPurchase,
					})}
					cardContent={
						<>
							<div className="flex flex-col-reverse items-center sm:flex-row">
								<div className="space-y-2 text-center sm:text-left">
									<Text>
										<Strong>
											{laboratoryPurchase.temporarly_hide_gda_order_id
												? "Nombre de paciente pendiente"
												: laboratoryPurchase.full_name}
										</Strong>
									</Text>

									<div className="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
										<Text>
											{laboratoryPurchase.formatted_total}
										</Text>

										{laboratoryPurchase.transactions &&
											laboratoryPurchase.transactions
												.length > 0 && (
												<PaymentMethodBadge
													transaction={
														laboratoryPurchase
															.transactions[0]
													}
												/>
											)}
									</div>

									<div className="flex flex-col gap-2">
										<Badge color="slate">
											{laboratoryPurchase.invoice ? (
												<>
													<DocumentTextIcon className="size-4" />
													Factura generada
												</>
											) : laboratoryPurchase.invoice_request ? (
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
											{laboratoryPurchase.results ? (
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
									{laboratoryPurchase.formatted_created_at}
								</Text>
								<div className="flex flex-col items-center gap-2 sm:flex-row sm:gap-0">
									<img
										src={`/images/gda/GDA-${laboratoryPurchase.brand.toUpperCase()}.png`}
										className="-mr-4 w-36 rounded-lg object-contain"
									/>

									<Badge>
										<QrCodeIcon className="size-6" />
										<span className="text-xl">
											{laboratoryPurchase.gda_order_id}
										</span>
									</Badge>
								</div>
								<Subheading className="flex items-center group-hover:underline">
									Ver detalle
									<ArrowRightIcon className="ml-1 size-5 transform transition-transform group-hover:translate-x-1 group-hover:scale-125" />
								</Subheading>
							</div>
						</>
					}
					tableHeaders={
						<>
							<TableHeader>Estudio</TableHeader>
							<TableHeader>Código</TableHeader>
							<TableHeader className="text-right">
								Precio
							</TableHeader>
						</>
					}
					tableRows={
						<>
							{laboratoryPurchase.laboratory_purchase_items.map(
								(laboratoryPurchaseItem) => (
									<TableRow key={laboratoryPurchaseItem.id}>
										<TableCell>
											{laboratoryPurchaseItem.name}
										</TableCell>
										<TableCell>
											{laboratoryPurchaseItem.gda_id}
										</TableCell>
										<TableCell className="text-right">
											{
												laboratoryPurchaseItem.formatted_price
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
