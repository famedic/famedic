import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import {
	DocumentTextIcon,
	ClockIcon,
	MagnifyingGlassIcon,
	ExclamationTriangleIcon,
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

export default function LaboratoryPurchases({ laboratoryPurchases, laboratoryQuotes }) {
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

			<Navbar className="-mt-6 mb-6 sm:mb-10">
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

			<form className="mb-6 sm:mb-10" onSubmit={updateResults}>
				<div className="max-w-full md:max-w-md">
					<InputGroup>
						<MagnifyingGlassIcon />
						<Input
							placeholder="Buscar pedidos y cotizaciones"
							value={data.search}
							onChange={(e) => setData("search", e.target.value)}
						/>
					</InputGroup>
				</div>
			</form>

			{/* Sección de Cotizaciones */}
			{laboratoryQuotes.length > 0 && (
				<div className="mb-8 sm:mb-12">
					<Subheading className="mb-4 sm:mb-6 text-base sm:text-lg font-semibold">
						Mis Cotizaciones
					</Subheading>
					<LaboratoryQuotesList
						laboratoryQuotes={laboratoryQuotes}
					/>
				</div>
			)}

			{/* Sección de Pedidos */}
			<LaboratoryPurchasesList
				laboratoryPurchases={laboratoryPurchases}
			/>
		</SettingsLayout>
	);
}

// Nuevo componente para Cotizaciones
function LaboratoryQuotesList({ laboratoryQuotes }) {
	return (
		<div className="space-y-4 sm:space-y-6">
			{laboratoryQuotes.map((quote) => (
				<PurchaseCard
					key={quote.id}
					href={route("laboratory.quote.success", { quote: quote.id })}
					cardContent={
						<>
							<div className="flex flex-col gap-4 sm:flex-row sm:justify-between">
								{/* Información principal - Izquierda */}
								<div className="flex-1 min-w-0 space-y-3">
									<div className="text-center sm:text-left">
										<Text className="text-sm sm:text-base">
											<Strong className="break-words">
												Cotización #{quote.gda_acuse || quote.id}
											</Strong>
										</Text>
									</div>

									{/* Precio y estado */}
									<div className="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
										<Text className="text-base sm:text-lg font-semibold whitespace-nowrap">
											{quote.formatted_total}
										</Text>

										
									</div>

									{/* Badges informativos */}
									<div className="flex flex-col gap-2">
										<Badge color="blue" className="justify-center sm:justify-start">
											<ClockIcon className="size-3 sm:size-4" />
											<span className="text-xs sm:text-sm">Vence: {quote.formatted_expires_at}</span>
										</Badge>
										{quote.appointment && (
											<Badge color="slate" className="justify-center sm:justify-start">
												<DocumentTextIcon className="size-3 sm:size-4" />
												<span className="text-xs sm:text-sm">Cita programada</span>
											</Badge>
										)}
										<Badge color={
											quote.status === 'pending_branch_payment' ? 'yellow' : 
											quote.status === 'expired' ? 'red' : 'green'
										} className="flex-shrink-0">
											{quote.status === 'pending_branch_payment' && (
												<>
													<ClockIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Pendiente</span>
												</>
											)}
											{quote.status === 'expired' && (
												<>
													<ExclamationTriangleIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Expirada</span>
												</>
											)}
											{quote.status === 'completed' && (
												<>
													<DocumentTextIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Completada</span>
												</>
											)}
										</Badge>
									</div>
								</div>

								{/* Información secundaria - Derecha */}
								<div className="flex flex-col items-center gap-3 sm:items-end sm:gap-2">
									<Text className="text-xs sm:text-sm text-gray-500 text-center sm:text-right">
										Creada: {quote.formatted_created_at}
									</Text>
									
									<div className="flex flex-col items-center gap-2 sm:flex-row sm:items-end">
										{quote.gda_acuse && (
											<Badge className="order-2 sm:order-1">
												<QrCodeIcon className="size-4 sm:size-6" />
												<span className="text-sm sm:text-xl font-mono">
													{quote.gda_acuse}
												</span>
											</Badge>
										)}
										<img
											src={`/images/gda/GDA-${quote.laboratory_brand?.toUpperCase() || 'GDA'}.png`}
											className="order-1 sm:order-2 w-24 sm:w-36 rounded-lg object-contain flex-shrink-0"
											alt={`Logo ${quote.laboratory_brand}`}
										/>
									</div>

									<Subheading className="flex items-center text-sm sm:text-base group-hover:underline">
										Ver cotización
										<ArrowRightIcon className="ml-1 size-4 sm:size-5 transform transition-transform group-hover:translate-x-1 group-hover:scale-125" />
									</Subheading>
								</div>
							</div>
						</>
					}
					tableHeaders={
						<>
							<TableHeader className="text-xs sm:text-sm">Estudio</TableHeader>
							<TableHeader className="text-xs sm:text-sm">Cantidad</TableHeader>
							<TableHeader className="text-right text-xs sm:text-sm">
								Precio
							</TableHeader>
						</>
					}
					tableRows={
						<>
							{quote.items.map((item, index) => (
								<TableRow key={index}>
									<TableCell className="text-xs sm:text-sm">
										<span className="break-words">{item.name}</span>
									</TableCell>
									<TableCell className="text-xs sm:text-sm">
										{item.quantity || 1}
									</TableCell>
									<TableCell className="text-right text-xs sm:text-sm whitespace-nowrap">
										${(item.price * (item.quantity || 1)).toFixed(2)} MXN
									</TableCell>
								</TableRow>
							))}
						</>
					}
				/>
			))}
		</div>
	);
}

// Componente existente para Pedidos (mejorado para responsividad)
function LaboratoryPurchasesList({ laboratoryPurchases }) {
	if (laboratoryPurchases.length === 0)
		return (
			<EmptyListCard
				heading="No tienes pedidos"
				message="Puedes hacer pedidos de laboratorios y farmacia en línea desde el menú principal."
			/>
		);

	return (
		<div className="mb-12 sm:mb-20 space-y-12 sm:space-y-20">
			{laboratoryPurchases.map((laboratoryPurchase) => (
				<PurchaseCard
					key={laboratoryPurchase.id}
					href={route("laboratory-purchases.show", {
						laboratory_purchase: laboratoryPurchase,
					})}
					cardContent={
						<>
							<div className="flex flex-col gap-4 sm:flex-row sm:justify-between">
								{/* Información principal - Izquierda */}
								<div className="flex-1 min-w-0 space-y-3">
									<div className="text-center sm:text-left">
										<Text className="text-sm sm:text-base">
											<Strong className="break-words">
												{laboratoryPurchase.temporarly_hide_gda_order_id
													? "Nombre de paciente pendiente"
													: laboratoryPurchase.full_name}
											</Strong>
										</Text>
									</div>

									{/* Precio y método de pago */}
									<div className="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
										<Text className="text-sm sm:text-base whitespace-nowrap">
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
													className="flex-shrink-0"
												/>
											)}
									</div>

									{/* Badges informativos */}
									<div className="flex flex-col gap-2">
										<Badge color="slate" className="justify-center sm:justify-start">
											{laboratoryPurchase.invoice ? (
												<>
													<DocumentTextIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Factura generada</span>
												</>
											) : laboratoryPurchase.invoice_request ? (
												<>
													<ClockIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Factura solicitada</span>
												</>
											) : (
												<>
													<DocumentTextIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Factura no solicitada</span>
												</>
											)}
										</Badge>

										<Badge color="slate" className="justify-center sm:justify-start">
											{laboratoryPurchase.results ? (
												<>
													<DocumentTextIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Resultados cargados</span>
												</>
											) : (
												<>
													<ClockIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Resultados pendientes</span>
												</>
											)}
										</Badge>
									</div>
								</div>

								{/* Información secundaria - Derecha */}
								<div className="flex flex-col items-center gap-3 sm:items-end sm:gap-2">
									<Text className="text-xs sm:text-sm text-gray-500 text-center sm:text-right">
										{laboratoryPurchase.formatted_created_at}
									</Text>
									
									<div className="flex flex-col items-center gap-2 sm:flex-row sm:items-end">
										<Badge className="order-2 sm:order-1">
											<QrCodeIcon className="size-4 sm:size-6" />
											<span className="text-sm sm:text-xl font-mono">
												{laboratoryPurchase.gda_order_id}
											</span>
										</Badge>
										<img
											src={`/images/gda/GDA-${laboratoryPurchase.brand.toUpperCase()}.png`}
											className="order-1 sm:order-2 w-24 sm:w-36 rounded-lg object-contain flex-shrink-0"
											alt={`Logo ${laboratoryPurchase.brand}`}
										/>
									</div>

									<Subheading className="flex items-center text-sm sm:text-base group-hover:underline">
										Ver detalle
										<ArrowRightIcon className="ml-1 size-4 sm:size-5 transform transition-transform group-hover:translate-x-1 group-hover:scale-125" />
									</Subheading>
								</div>
							</div>
						</>
					}
					tableHeaders={
						<>
							<TableHeader className="text-xs sm:text-sm">Estudio</TableHeader>
							<TableHeader className="text-xs sm:text-sm">Código</TableHeader>
							<TableHeader className="text-right text-xs sm:text-sm">
								Precio
							</TableHeader>
						</>
					}
					tableRows={
						<>
							{laboratoryPurchase.laboratory_purchase_items.map(
								(laboratoryPurchaseItem) => (
									<TableRow key={laboratoryPurchaseItem.id}>
										<TableCell className="text-xs sm:text-sm">
											<span className="break-words">{laboratoryPurchaseItem.name}</span>
										</TableCell>
										<TableCell className="text-xs sm:text-sm">
											<span className="font-mono">{laboratoryPurchaseItem.gda_id}</span>
										</TableCell>
										<TableCell className="text-right text-xs sm:text-sm whitespace-nowrap">
											{laboratoryPurchaseItem.formatted_price}
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