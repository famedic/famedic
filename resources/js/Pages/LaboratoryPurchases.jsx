import SettingsLayout from "@/Layouts/SettingsLayout";
import { GradientHeading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { ArrowRightIcon } from "@heroicons/react/20/solid";
import {
	DocumentTextIcon,
	ClockIcon,
	ExclamationTriangleIcon,
} from "@heroicons/react/16/solid";
import { FunnelIcon } from "@heroicons/react/24/outline";
import { TableCell, TableHeader, TableRow } from "@/Components/Catalyst/table";
import { Navbar, NavbarItem } from "@/Components/Catalyst/navbar";
import { QrCodeIcon } from "@heroicons/react/24/solid";
import EmptyListCard from "@/Components/EmptyListCard";
import PurchaseCard from "@/Components/PurchaseCard";
import { Badge } from "@/Components/Catalyst/badge";
import { useForm, Link, router } from "@inertiajs/react";
import { Input } from "@/Components/Catalyst/input";
import LaboratoryPurchaseDashboardCard from "@/Components/Laboratory/LaboratoryPurchaseDashboardCard";
import { Button } from "@/Components/Catalyst/button";
import OtpModal from "@/Components/OtpModal";
import { useEffect, useMemo, useRef, useState } from "react";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";

async function checkLabResultsOtpVerified(purchaseId) {
	try {
		const statusUrl = route("otp.status", { laboratory_purchase: purchaseId });
		const res = await fetch(statusUrl, {
			method: "GET",
			credentials: "same-origin",
			headers: { Accept: "application/json" },
		});
		const data = await res.json().catch(() => ({}));
		return res.ok && Boolean(data?.verified);
	} catch {
		return false;
	}
}

export default function LaboratoryPurchases({
	purchaseCards = [],
	pagination = null,
	summary = { pending_count: 0, ready_count: 0 },
	filters: filtersProp = {},
	filterOptions = {},
	laboratoryQuotes = [],
}) {
	const pendingAfterOtpRef = useRef(null);
	const [showOtpModal, setShowOtpModal] = useState(false);
	const [otpPurchaseId, setOtpPurchaseId] = useState(null);
	const [showFilters, setShowFilters] = useState(false);

	const requireOtpThen = async (purchaseId, fn) => {
		const verified = await checkLabResultsOtpVerified(purchaseId);
		if (verified) {
			fn?.();
			return true;
		}
		pendingAfterOtpRef.current = fn;
		setOtpPurchaseId(purchaseId);
		setShowOtpModal(true);
		return false;
	};

	const handleOtpSuccess = () => {
		setShowOtpModal(false);
		const next = pendingAfterOtpRef.current;
		pendingAfterOtpRef.current = null;
		setOtpPurchaseId(null);
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
		});
		// eslint-disable-next-line react-hooks/exhaustive-deps -- sincronizar con respuesta Inertia
	}, [filtersKey]);

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
			(data.end_date || "") !== (filtersProp.end_date || ""),
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
		return count;
	}, [filtersProp]);

	return (
		<SettingsLayout title="Mis pedidos">
			<GradientHeading>Mis pedidos de laboratorio</GradientHeading>
			<Text className="mt-2 max-w-3xl text-base text-zinc-600 dark:text-slate-400">
				Aquí ves el estado de cada estudio y puedes abrir resultados o facturas sin entrar al detalle.
			</Text>

			<Navbar className="-mt-2 mb-6 sm:mb-8">
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

			<form className="mb-8 space-y-4" onSubmit={applyFilters}>
				<div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
						placeholder="Nombre, folio o estudio"
					/>
					<div className="flex items-center justify-end gap-2">
						<Button
							type="button"
							outline
							className="w-full"
							onClick={() => setShowFilters((previous) => !previous)}
						>
							{activeFiltersCount ? (
								<FilterCountBadge count={activeFiltersCount} />
							) : (
								<FunnelIcon />
							)}
							Filtros
						</Button>
					</div>
				</div>

				{showFilters && (
					<div className="space-y-4 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-slate-700 dark:bg-slate-900/50 sm:p-6">
						<Subheading className="text-base sm:text-lg">Filtrar pedidos</Subheading>
						<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
							<ListboxFilter
								label="Paciente"
								placeholder="Paciente"
								value={data.patient}
								onChange={(value) => setData("patient", value)}
							>
								<ListboxOption value="">
									<ListboxLabel>Todos los pacientes</ListboxLabel>
								</ListboxOption>
								{(filterOptions.patients || []).map((opt) => (
									<ListboxOption key={opt.value} value={opt.value}>
										<ListboxLabel>{opt.label}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>

							<ListboxFilter
								label="Estado del estudio"
								value={data.study_status}
								onChange={(value) => setData("study_status", value)}
							>
								{(filterOptions.study_statuses || []).map((opt) => (
									<ListboxOption key={opt.value} value={opt.value}>
										<ListboxLabel>{opt.label}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>

							<ListboxFilter
								label="Forma de pago"
								value={data.payment_method}
								onChange={(value) => setData("payment_method", value)}
							>
								{(filterOptions.payment_methods || []).map((opt) => (
									<ListboxOption
										key={opt.value === "" ? "any-payment-method" : opt.value}
										value={opt.value}
									>
										<ListboxLabel>{opt.label}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>

							<ListboxFilter
								label="Laboratorio"
								value={data.brand}
								onChange={(value) => setData("brand", value)}
							>
								<ListboxOption value="">
									<ListboxLabel>Todos</ListboxLabel>
								</ListboxOption>
								{(filterOptions.laboratory_brands || []).map((opt) => (
									<ListboxOption key={opt.value} value={opt.value}>
										<ListboxLabel>{opt.label}</ListboxLabel>
									</ListboxOption>
								))}
							</ListboxFilter>

							<DateFilter
								label="Desde"
								value={data.start_date}
								onChange={(value) => setData("start_date", value)}
							/>
							<DateFilter
								label="Hasta"
								value={data.end_date}
								onChange={(value) => setData("end_date", value)}
							/>
						</div>
						<div className="flex flex-wrap gap-3">
							<Button
								type="button"
								outline
								className="min-h-[48px] text-base"
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
										},
										{ preserveScroll: true, replace: true },
									);
								}}
							>
								Limpiar
							</Button>
						</div>
					</div>
				)}

				{showUpdateButton && (
					<div className="flex flex-wrap gap-3">
						<Button
							type="submit"
							disabled={processing}
							className="min-h-[48px] min-w-[160px] text-base"
						>
							{processing ? "Buscando..." : "Aplicar filtros"}
						</Button>
					</div>
				)}
			</form>

			{laboratoryQuotes.length > 0 && (
				<div className="mb-10 sm:mb-12">
					<Subheading className="mb-4 text-base font-semibold sm:text-lg">
						Pedidos con pago en sucursal
					</Subheading>
					<LaboratoryQuotesList laboratoryQuotes={laboratoryQuotes} />
				</div>
			)}

			<Subheading className="mb-4 text-base font-semibold sm:text-lg">
				Pagos en línea
			</Subheading>

			{processing && (
				<div className="mb-4 text-sm text-zinc-500 dark:text-slate-400">Actualizando lista…</div>
			)}

			{!processing && purchaseCards.length === 0 && (
				<EmptyListCard
					heading="No hay pedidos con estos filtros"
					message="Prueba cambiar paciente, fechas o estado. También puedes hacer un nuevo pedido desde el menú principal."
				/>
			)}

			{purchaseCards.length > 0 && (
				<div className="space-y-6">
					{purchaseCards.map((purchase) => (
						<LaboratoryPurchaseDashboardCard
							key={purchase.id}
							purchase={purchase}
							requireOtpThen={requireOtpThen}
						/>
					))}
				</div>
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
								className="inline-flex min-h-[48px] min-w-[120px] items-center justify-center rounded-lg border border-zinc-300 px-4 text-base font-semibold hover:bg-zinc-50 dark:border-slate-600 dark:hover:bg-slate-800"
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
								className="inline-flex min-h-[48px] min-w-[120px] items-center justify-center rounded-lg border border-zinc-300 px-4 text-base font-semibold hover:bg-zinc-50 dark:border-slate-600 dark:hover:bg-slate-800"
								preserveScroll
							>
								Siguiente
							</Link>
						)}
					</div>
				</div>
			)}

			{showOtpModal && otpPurchaseId != null && (
				<OtpModal
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
											<Strong className="break-words">
												Pedido #{quote.gda_order_id || quote.id}
											</Strong>
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
									<Subheading className="flex items-center text-sm sm:text-base group-hover:underline">
										Ver pedido
										<ArrowRightIcon className="ml-1 size-4 transform transition-transform group-hover:translate-x-1 sm:size-5" />
									</Subheading>
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
