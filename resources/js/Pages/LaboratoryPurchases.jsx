import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import {
	DocumentTextIcon,
	ClockIcon,
	ExclamationTriangleIcon,
} from "@heroicons/react/16/solid";
import { TableCell, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import { QrCodeIcon } from "@heroicons/react/24/solid";
import EmptyListCard from "@/Components/EmptyListCard";
import PurchaseCard from "@/Components/PurchaseCard";
import { Badge } from "@/Components/Catalyst/badge";
import { useForm, Link, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import SecurityVerificationModal from "@/Components/SecurityVerificationModal";
import { useEffect, useMemo, useRef, useState } from "react";
import OrdersSummaryCards from "@/Components/LaboratoryPurchases/OrdersSummaryCards";
import OrdersFilters from "@/Components/LaboratoryPurchases/OrdersFilters";
import OrdersTable from "@/Components/LaboratoryPurchases/OrdersTable";
import OrderCardMobile from "@/Components/LaboratoryPurchases/OrderCardMobile";
import { exportLaboratoryPurchasesPageCsv } from "@/lib/laboratoryPurchaseOrderUi";
import formatMmSs from "@/Utils/formatMmSs";

async function fetchLabResultsOtpStatus(purchaseId) {
	try {
		const statusUrl = route("otp.status", { laboratory_purchase: purchaseId });
		const res = await fetch(statusUrl, {
			method: "GET",
			credentials: "same-origin",
			headers: { Accept: "application/json" },
		});
		const data = await res.json().catch(() => ({}));
		return {
			verified: res.ok && Boolean(data?.verified),
			expiresIn: Number(data?.expires_in ?? 0),
		};
	} catch {
		return { verified: false, expiresIn: 0 };
	}
}

function buildEmptyCopy({ pipeline, hasFilterActivity, total }) {
	if (total === 0 && !hasFilterActivity) {
		return {
			heading: "Aún no tienes pedidos de laboratorio",
			message:
				"Cuando compres estudios en línea aparecerán aquí con su estado, resultados y opciones de facturación.",
		};
	}
	if (pipeline === "processing") {
		return {
			heading: "No hay pedidos en proceso",
			message: "Todos tus pedidos tienen resultados o prueba limpiar filtros para ver el historial completo.",
		};
	}
	if (pipeline === "completed") {
		return {
			heading: "No hay resultados en esta vista",
			message: "Cuando el laboratorio publique resultados podrás abrirlos desde aquí. También revisa otros filtros.",
		};
	}
	if (pipeline === "invoiced") {
		return {
			heading: "No hay facturas que coincidan",
			message:
				"Solo mostramos pedidos con factura generada y solicitud de factura registrada. Puedes solicitar factura en el detalle del pedido.",
		};
	}
	return {
		heading: "No hay pedidos con estos criterios",
		message: "Ajusta la búsqueda, el embudo o los filtros avanzados e intenta de nuevo.",
	};
}

export default function LaboratoryPurchases({
	purchaseCards = [],
	pagination = null,
	summary = { pending_count: 0, ready_count: 0, processing_count: 0, completed_count: 0, invoiced_count: 0 },
	filters: filtersProp = {},
	filterOptions = {},
	laboratoryQuotes = [],
}) {
	const pendingAfterOtpRef = useRef(null);
	const [showOtpModal, setShowOtpModal] = useState(false);
	const [otpPurchaseId, setOtpPurchaseId] = useState(null);
	const [showFilters, setShowFilters] = useState(false);
	const [otpTrustSecondsLeft, setOtpTrustSecondsLeft] = useState(0);

	const requireOtpThen = async (purchaseId, fn) => {
		const status = await fetchLabResultsOtpStatus(purchaseId);
		if (status.verified) {
			setOtpTrustSecondsLeft(Math.max(0, Math.floor(status.expiresIn)));
			fn?.();
			return true;
		}
		pendingAfterOtpRef.current = fn;
		setOtpPurchaseId(purchaseId);
		setShowOtpModal(true);
		return false;
	};

	const handleOtpSuccess = (payload = {}) => {
		setShowOtpModal(false);
		const next = pendingAfterOtpRef.current;
		pendingAfterOtpRef.current = null;
		setOtpPurchaseId(null);
		if (typeof payload?.expires_in === "number") {
			setOtpTrustSecondsLeft(Math.max(0, Math.floor(payload.expires_in)));
		}
		if (typeof next === "function") next();
	};

	const handleOtpModalClose = () => {
		pendingAfterOtpRef.current = null;
		setShowOtpModal(false);
		setOtpPurchaseId(null);
	};

	const { data, setData, get, processing } = useForm({
		search: filtersProp.search ?? "",
		patient: filtersProp.patient ?? "",
		study_status: filtersProp.study_status ?? "all",
		payment_method: filtersProp.payment_method ?? "",
		brand: filtersProp.brand ?? "",
		start_date: filtersProp.start_date ?? "",
		end_date: filtersProp.end_date ?? "",
		pipeline: filtersProp.pipeline ?? "all",
	});

	const filtersKey = JSON.stringify(filtersProp ?? {});
	useEffect(() => {
		setData({
			search: filtersProp.search ?? "",
			patient: filtersProp.patient ?? "",
			study_status: filtersProp.study_status ?? "all",
			payment_method: filtersProp.payment_method ?? "",
			brand: filtersProp.brand ?? "",
			start_date: filtersProp.start_date ?? "",
			end_date: filtersProp.end_date ?? "",
			pipeline: filtersProp.pipeline ?? "all",
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps -- sincronizar con respuesta Inertia
	}, [filtersKey]);

	useEffect(() => {
		const timer = setInterval(() => {
			setOtpTrustSecondsLeft((prev) => (prev > 0 ? prev - 1 : 0));
		}, 1000);
		return () => clearInterval(timer);
	}, []);

	useEffect(() => {
		const firstPurchaseId = purchaseCards?.[0]?.id;
		if (!firstPurchaseId) {
			setOtpTrustSecondsLeft(0);
			return;
		}
		fetchLabResultsOtpStatus(firstPurchaseId).then((status) => {
			setOtpTrustSecondsLeft(status.verified ? Math.max(0, Math.floor(status.expiresIn)) : 0);
		});
	}, [purchaseCards]);

	const navigateWithFilters = (overrides = {}) => {
		router.get(
			route("laboratory-purchases.index"),
			{
				search: overrides.search ?? data.search,
				patient: overrides.patient ?? data.patient,
				study_status: overrides.study_status ?? data.study_status,
				payment_method: overrides.payment_method ?? data.payment_method,
				brand: overrides.brand ?? data.brand,
				start_date: overrides.start_date ?? data.start_date,
				end_date: overrides.end_date ?? data.end_date,
				deleted: "false",
				pipeline: Object.prototype.hasOwnProperty.call(overrides, "pipeline") ? overrides.pipeline : data.pipeline,
			},
			{ preserveState: true, preserveScroll: true, replace: true },
		);
	};

	const applyFilters = (e) => {
		e?.preventDefault?.();
		get(route("laboratory-purchases.index"), {
			preserveState: true,
			preserveScroll: true,
			replace: true,
		});
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filtersProp.search || "") ||
			(data.patient || "") !== (filtersProp.patient || "") ||
			(data.study_status || "all") !== (filtersProp.study_status || "all") ||
			(data.payment_method || "") !== (filtersProp.payment_method || "") ||
			(data.brand || "") !== (filtersProp.brand || "") ||
			(data.start_date || "") !== (filtersProp.start_date || "") ||
			(data.end_date || "") !== (filtersProp.end_date || "") ||
			(data.pipeline || "all") !== (filtersProp.pipeline || "all"),
		[data, filtersProp],
	);

	const activeFiltersCount = useMemo(() => {
		let count = 0;
		if (filtersProp.search) count += 1;
		if (filtersProp.patient) count += 1;
		if (filtersProp.study_status && filtersProp.study_status !== "all") count += 1;
		if (filtersProp.payment_method) count += 1;
		if (filtersProp.brand) count += 1;
		if (filtersProp.start_date) count += 1;
		if (filtersProp.end_date) count += 1;
		if (filtersProp.pipeline && filtersProp.pipeline !== "all") count += 1;
		return count;
	}, [filtersProp]);

	const hasFilterActivity = useMemo(() => activeFiltersCount > 0, [activeFiltersCount]);

	const emptyCopy = buildEmptyCopy({
		pipeline: filtersProp.pipeline ?? "all",
		hasFilterActivity,
		total: pagination?.total ?? 0,
	});

	const onPipelineChange = (pipeline) => {
		setData("pipeline", pipeline);
		navigateWithFilters({ pipeline });
	};

	return (
		<SettingsLayout title="Mis pedidos">
			<div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
				<div>
					<GradientHeading>Mis pedidos de laboratorio</GradientHeading>
					<Text className="mt-2 max-w-2xl text-base text-zinc-600 dark:text-slate-400">
						Consulta el estado de tus estudios, resultados y facturación en un solo lugar.
					</Text>
				</div>
				<div className="flex shrink-0 flex-col items-stretch gap-1 sm:items-end">
					<Button
						type="button"
						outline
						className="min-h-11 w-full sm:w-auto"
						disabled={purchaseCards.length === 0}
						onClick={() => exportLaboratoryPurchasesPageCsv(purchaseCards)}
					>
						Exportar historial
					</Button>
					<Text className="text-center text-xs text-zinc-500 dark:text-slate-500 sm:text-right">
						CSV de la página actual
					</Text>
				</div>
			</div>

			<div className="mt-8">
				<OrdersSummaryCards
					summary={summary}
					activePipeline={filtersProp.pipeline ?? "all"}
					onPipelineSelect={onPipelineChange}
				/>
			</div>

			<Navbar className="mt-8 border-b border-zinc-200/80 dark:border-slate-800">
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

			<form className="mt-6 space-y-6" onSubmit={applyFilters}>
				<OrdersFilters
					data={data}
					setData={setData}
					showFilters={showFilters}
					setShowFilters={setShowFilters}
					activeFiltersCount={activeFiltersCount}
					filterOptions={filterOptions}
					onPipelineChange={onPipelineChange}
				/>

				{showUpdateButton && (
					<div className="flex flex-wrap gap-3">
						<Button type="submit" disabled={processing} className="min-h-11 min-w-[160px]">
							{processing ? "Aplicando…" : "Aplicar cambios pendientes"}
						</Button>
					</div>
				)}

				<div className="flex flex-wrap gap-3 border-t border-zinc-100 pt-4 dark:border-slate-800">
					<Button
						type="button"
						outline
						className="min-h-11"
						onClick={() => {
							router.get(
								route("laboratory-purchases.index"),
								{
									search: "",
									patient: "",
									study_status: "all",
									payment_method: "",
									brand: "",
									start_date: "",
									end_date: "",
									deleted: "false",
									pipeline: "all",
								},
								{ preserveScroll: true, replace: true },
							);
						}}
					>
						Restablecer vista
					</Button>
				</div>
			</form>

			{laboratoryQuotes.length > 0 && (
				<div className="mb-10 mt-10 sm:mb-12">
					<Text className="mb-4 text-base font-semibold text-zinc-900 dark:text-white">Pedidos con pago en sucursal</Text>
					<LaboratoryQuotesList laboratoryQuotes={laboratoryQuotes} />
				</div>
			)}

			<Text className="mb-4 mt-10 text-base font-semibold text-zinc-900 dark:text-white">Pagos en línea</Text>
			{otpTrustSecondsLeft > 0 && (
				<div className="mb-4">
					<Badge color="green" className="inline-flex items-center gap-2 px-3 py-1.5">
						<ClockIcon className="size-4" />
						<span>Verificación activa · {formatMmSs(otpTrustSecondsLeft)} restantes</span>
					</Badge>
				</div>
			)}

			{processing && (
				<div className="mb-4 text-sm text-zinc-500 dark:text-slate-400">Actualizando lista…</div>
			)}

			{!processing && purchaseCards.length === 0 && (
				<EmptyListCard heading={emptyCopy.heading} message={emptyCopy.message} className="dark:border-slate-800" />
			)}

			{purchaseCards.length > 0 && (
				<>
					<OrdersTable purchases={purchaseCards} requireOtpThen={requireOtpThen} />
					<div className="space-y-3 md:hidden" aria-label="Lista de pedidos">
						{purchaseCards.map((purchase) => (
							<OrderCardMobile key={purchase.id} purchase={purchase} requireOtpThen={requireOtpThen} />
						))}
					</div>
				</>
			)}

			{pagination && pagination.last_page > 1 && (
				<div className="mt-10 flex flex-col items-center justify-between gap-4 sm:flex-row">
					<Text className="text-sm text-zinc-600 dark:text-slate-400">
						Mostrando {pagination.from ?? 0}–{pagination.to ?? 0} de {pagination.total} pedidos
					</Text>
					<div className="flex flex-wrap items-center gap-3">
						{pagination.prev_page_url && (
							<Link
								href={pagination.prev_page_url}
								className="inline-flex min-h-11 min-w-[120px] items-center justify-center rounded-xl border border-zinc-300 px-4 text-base font-semibold text-zinc-800 shadow-sm hover:bg-zinc-50 dark:border-slate-600 dark:text-white dark:hover:bg-slate-800"
								preserveScroll
							>
								Anterior
							</Link>
						)}
						<Text className="text-sm">
							Página {pagination.current_page} de {pagination.last_page}
						</Text>
						{pagination.next_page_url && (
							<Link
								href={pagination.next_page_url}
								className="inline-flex min-h-11 min-w-[120px] items-center justify-center rounded-xl border border-zinc-300 px-4 text-base font-semibold text-zinc-800 shadow-sm hover:bg-zinc-50 dark:border-slate-600 dark:text-white dark:hover:bg-slate-800"
								preserveScroll
							>
								Siguiente
							</Link>
						)}
					</div>
				</div>
			)}

			{showOtpModal && otpPurchaseId != null && (
				<SecurityVerificationModal
					isOpen={showOtpModal}
					purchaseId={otpPurchaseId}
					onSuccess={handleOtpSuccess}
					onClose={handleOtpModalClose}
				/>
			)}
		</SettingsLayout>
	);
}

const formatPrice = (price) => {
	if (price === undefined || price === null) return "";
	if (typeof price === "number") {
		return new Intl.NumberFormat("es-MX", {
			style: "currency",
			currency: "MXN",
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(price);
	}
	if (typeof price === "string") {
		const cleanPrice = price.replace(/[^\d.]/g, "");
		const numberPrice = parseFloat(cleanPrice);
		if (Number.isNaN(numberPrice)) return price;
		return new Intl.NumberFormat("es-MX", {
			style: "currency",
			currency: "MXN",
			minimumFractionDigits: 2,
			maximumFractionDigits: 2,
		}).format(numberPrice);
	}
	return price;
};

const convertFromCents = (price) => {
	if (price === undefined || price === null) return 0;
	const numericPrice = typeof price === "string" ? parseFloat(price) : price;
	if (Number.isNaN(numericPrice)) return 0;
	return numericPrice / 100;
};

const calculateItemTotal = (item) => {
	const quantity = item.quantity || 1;
	const price = convertFromCents(item.price);
	return price * quantity;
};

const fixFormattedTotal = (totalValue, formattedTotal) => {
	if (totalValue !== undefined && totalValue !== null) {
		return formatPrice(convertFromCents(totalValue));
	}
	if (formattedTotal) {
		return formatPrice(formattedTotal);
	}
	return "";
};

function LaboratoryQuotesList({ laboratoryQuotes }) {
	return (
		<div className="space-y-4 sm:space-y-6">
			{laboratoryQuotes.map((quote) => (
				<PurchaseCard
					key={quote.id}
					href={route("laboratory.quote.show", { quote: quote.id })}
					cardContent={
						<>
							<div className="flex flex-col gap-4 sm:flex-row sm:justify-between">
								<div className="min-w-0 flex-1 space-y-3">
									<div className="text-center sm:text-left">
										<Text className="text-sm sm:text-base">
											<Strong className="break-words">Pedido #{quote.gda_order_id || quote.id}</Strong>
										</Text>
									</div>
									<div className="flex flex-col items-center gap-2 sm:flex-row sm:justify-between">
										<Text className="whitespace-nowrap text-sm sm:text-base">
											{fixFormattedTotal(quote.total, quote.formatted_total)} MXN
										</Text>
									</div>
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
										<Badge
											color={
												quote.status === "pending_branch_payment"
													? "yellow"
													: quote.status === "expired"
														? "red"
														: "green"
											}
											className="flex-shrink-0"
										>
											{quote.status === "pending_branch_payment" && (
												<>
													<ClockIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Pendiente</span>
												</>
											)}
											{quote.status === "expired" && (
												<>
													<ExclamationTriangleIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Expirada</span>
												</>
											)}
											{quote.status === "completed" && (
												<>
													<DocumentTextIcon className="size-3 sm:size-4" />
													<span className="text-xs sm:text-sm">Completada</span>
												</>
											)}
										</Badge>
									</div>
								</div>
								<div className="flex flex-col items-center gap-3 sm:items-end sm:gap-2">
									<img
										src={`/images/gda/GDA-${(quote.laboratory_brand || "GDA").toUpperCase()}.png`}
										className="order-1 h-24 w-auto max-w-[9rem] flex-shrink-0 rounded-lg object-contain sm:order-2 sm:h-36 sm:max-w-[10rem]"
										alt=""
									/>
									<Text className="flex items-center text-sm font-semibold text-zinc-900 group-hover:underline dark:text-white sm:text-base">
										Ver pedido
										<ArrowRightIcon className="ml-1 size-4 transform transition-transform group-hover:translate-x-1 sm:size-5" />
									</Text>
								</div>
							</div>
						</>
					}
					tableHeaders={
						<>
							<TableHeader className="text-xs sm:text-sm">Estudio</TableHeader>
							<TableHeader className="text-xs sm:text-sm">Cantidad</TableHeader>
							<TableHeader className="text-right text-xs sm:text-sm">Precio</TableHeader>
						</>
					}
					tableRows={
						<>
							{quote.items.map((item, index) => (
								<TableRow key={index}>
									<TableCell className="text-xs sm:text-sm">
										<span className="break-words">{item.name}</span>
									</TableCell>
									<TableCell className="text-xs sm:text-sm">{item.quantity || 1}</TableCell>
									<TableCell className="whitespace-nowrap text-right text-xs sm:text-sm">
										{formatPrice(calculateItemTotal(item))} MXN
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
