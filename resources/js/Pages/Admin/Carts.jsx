import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import clsx from "clsx";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ResultsAndExport from "@/Components/ResultsAndExport";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import {
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
	CheckCircleIcon,
	ClockIcon,
	MagnifyingGlassIcon,
	ShoppingCartIcon,
	XCircleIcon,
} from "@heroicons/react/16/solid";
import {
	BeakerIcon,
	FunnelIcon,
	ShoppingBagIcon,
} from "@heroicons/react/24/outline";

function MetricCard({ title, value, valueClassName }) {
	return (
		<div className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-600/80 dark:bg-zinc-800/90">
			<p className="text-xs font-medium leading-snug text-zinc-600 dark:text-zinc-300">
				{title}
			</p>
			<p
				className={clsx(
					"mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-50",
					valueClassName,
				)}
			>
				{value}
			</p>
		</div>
	);
}

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

function CheckoutTag({ active, label, color }) {
	return (
		<Badge color={active ? color || "green" : "zinc"}>
			<CheckCircleIcon
				className={clsx("size-3.5", !active && "opacity-40")}
			/>
			{label}
		</Badge>
	);
}

function appointmentCheckoutTag(appointment) {
	if (!appointment) {
		return {
			active: false,
			label: "Sin cita",
			color: "zinc",
		};
	}

	if (!appointment.is_confirmed) {
		return {
			active: true,
			label: "Cita por confirmar",
			color: "amber",
		};
	}

	if (!appointment.has_linked_purchase) {
		return {
			active: true,
			label: "Cita confirmada, sin pago",
			color: "violet",
		};
	}

	return {
		active: true,
		label: "Cita confirmada",
		color: "green",
	};
}

function CheckoutSummaryCell({ cart }) {
	if (cart.type !== "lab") {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				No aplica
			</Text>
		);
	}

	const entries = cart.checkout_summary ?? [];

	if (entries.length === 0) {
		return (
			<Text className="text-xs text-zinc-500 dark:text-zinc-400">
				Sin avance
			</Text>
		);
	}

	return (
		<div className="space-y-3">
			{entries.map((entry) => {
				const wantsPhoneCall = !!entry.appointment?.has_phone_call_intent;
				const leftCallbackSchedule =
					!!entry.appointment?.has_callback_info;
				const appointmentTag = appointmentCheckoutTag(entry.appointment);

				return (
					<div
						key={entry.id}
						className="rounded-lg border border-zinc-200/80 bg-zinc-50/70 p-2 dark:border-zinc-700/70 dark:bg-zinc-900/40"
					>
						{entry.brand_label ? (
							<div className="mb-1.5">
								<Badge color="slate">{entry.brand_label}</Badge>
							</div>
						) : null}
						<div className="flex flex-col items-start gap-1">
							<CheckoutTag
								active={!!entry.patient_name}
								label="Paciente"
							/>
							<CheckoutTag
								active={!!entry.address_short}
								label="Dirección"
							/>
							<CheckoutTag
								active={!!entry.payment_method_label}
								label="Pago"
							/>
							<CheckoutTag
								active={wantsPhoneCall}
								label="Llamó"
								color="sky"
							/>
							<CheckoutTag
								active={leftCallbackSchedule}
								label="Llamada solicitada"
								color="violet"
							/>
							<CheckoutTag
								active={appointmentTag.active}
								label={appointmentTag.label}
								color={appointmentTag.color}
							/>
						</div>
					</div>
				);
			})}
		</div>
	);
}

function CartFilters({ data, setData }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Tipo"
				placeholder="Tipo"
				value={data.type}
				onChange={(value) => setData("type", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="pharmacy" className="group">
					<ShoppingBagIcon />
					<ListboxLabel>Farmacia</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="lab" className="group">
					<BeakerIcon />
					<ListboxLabel>Laboratorio</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Estatus"
				placeholder="Estatus"
				value={data.display_status}
				onChange={(value) => setData("display_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activo</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="abandoned" className="group">
					<XCircleIcon />
					<ListboxLabel>Abandonado</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="completed" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Comprado</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<DateFilter
				label="Desde (actividad)"
				value={data.start_date}
				onChange={(value) => setData("start_date", value)}
			/>
			<DateFilter
				label="Hasta (actividad)"
				value={data.end_date}
				onChange={(value) => setData("end_date", value)}
			/>
		</div>
	);
}

export default function Carts({
	carts,
	filters,
	metrics,
	abandonedThresholdMinutes = 30,
	canViewCartDetails = true,
	canExport = false,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		type: filters.type || "",
		display_status: filters.display_status || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.carts.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.type || "") !== (filters.type || "") ||
			(data.display_status || "") !== (filters.display_status || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];
		if (filters.search) {
			badges.push(
				<Badge key="s" color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}
		if (filters.type === "pharmacy") {
			badges.push(
				<Badge key="t" color="slate">
					<ShoppingBagIcon className="size-4" />
					Farmacia
				</Badge>,
			);
		} else if (filters.type === "lab") {
			badges.push(
				<Badge key="t" color="slate">
					<BeakerIcon className="size-4" />
					Laboratorio
				</Badge>,
			);
		}
		if (filters.display_status) {
			const { label } = statusBadge(filters.display_status);
			badges.push(
				<Badge key="d" color="famedic-lime">
					{label}
				</Badge>,
			);
		}
		if (filters.start_date || filters.end_date) {
			badges.push(
				<Badge key="r" color="slate">
					<CalendarDateRangeIcon className="size-4" />
					{filters.start_date || "…"} — {filters.end_date || "…"}
				</Badge>,
			);
		}
		return badges;
	}, [filters]);

	const filtersCount = filterBadges.length;

	return (
		<AdminLayout title="Carritos">
			<div className="space-y-8">
				<div>
					<Heading>Monitoreo · Carritos</Heading>
					<Text className="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
						Un carrito sin compra se marca como{" "}
						<Strong>Abandonado</Strong> tras{" "}
						<Strong>{abandonedThresholdMinutes} minutos</Strong> sin
						actividad (última actualización).
					</Text>
				</div>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por usuario..."
						/>
						<div className="flex items-center justify-end gap-2">
							<Button
								href={route("admin.carts.dashboard")}
								outline
								className="w-full sm:w-auto"
							>
								Dashboard
							</Button>
							<Button
								outline
								type="button"
								className="w-full"
								onClick={() => setShowFilters((v) => !v)}
							>
								{filtersCount > 0 ? (
									<FilterCountBadge count={filtersCount} />
								) : (
									<FunnelIcon />
								)}
								Filtros
							</Button>
						</div>
					</div>

					{showFilters && (
						<CartFilters data={data} setData={setData} />
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton type="submit" processing={processing} />
						</div>
					)}
				</form>

				{metrics && (
					<div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6">
						<MetricCard title="Activos" value={metrics.active} />
						<MetricCard
							title="Abandonados"
							value={metrics.abandoned}
							valueClassName="text-red-600 dark:text-red-300"
						/>
						<MetricCard
							title="Comprados"
							value={metrics.completed}
							valueClassName="text-blue-600 dark:text-sky-300"
						/>
						<MetricCard
							title="Espera confirmación de cita"
							value={metrics.appointment_pending_confirmation ?? 0}
							valueClassName="text-amber-600 dark:text-amber-300"
						/>
						<MetricCard
							title="Cita confirmada, sin pago"
							value={metrics.appointment_confirmed_pending_payment ?? 0}
							valueClassName="text-violet-600 dark:text-violet-300"
						/>
						<MetricCard
							title="Conversión (comprado / comprado+abandono)"
							value={
								metrics.conversion_percent != null
									? `${metrics.conversion_percent}%`
									: "—"
							}
							valueClassName="text-famedic-darker dark:text-famedic-lime"
						/>
					</div>
				)}

				<ResultsAndExport
					paginatedData={carts}
					filterBadges={filterBadges}
					canExport={canExport}
					filters={filters}
					exportUrl={route("admin.carts.export")}
					exportTitle="Descargar carritos"
				/>

				<PaginatedTable paginatedData={carts}>
					<Table>
						<TableHead>
							<TableRow>
								<TableHeader>Usuario</TableHeader>
								<TableHeader>Tipo</TableHeader>
								<TableHeader>Checkout</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>Actividad</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{carts.data.map((cart) => {
								const b = statusBadge(cart.display_status);
								return (
									<TableRow key={cart.id}>
										<TableCell>
											<div className="space-y-2">
												{cart.user ? (
													<div className="space-y-0.5">
														<Text className="!text-zinc-950 dark:!text-white">
															<Strong>
																{cart.user.full_name ||
																	cart.user.email}
															</Strong>
														</Text>
														{cart.user.email && (
															<Text className="text-xs">
																{cart.user.email}
															</Text>
														)}
													</div>
												) : (
													<Text className="text-sm">—</Text>
												)}
												{canViewCartDetails ? (
													<Button
														href={route("admin.carts.show", {
															cart: cart.id,
														})}
														outline
													>
														Ver detalle
													</Button>
												) : (
													<Text className="text-xs">Sin permiso</Text>
												)}
											</div>
										</TableCell>
									<TableCell>
										<div className="flex flex-col gap-1">
											<div className="flex items-center gap-1 text-sm text-zinc-950 dark:text-zinc-100">
												<ShoppingCartIcon className="size-4 text-zinc-400 dark:text-zinc-500" />
												{cart.type_label}
											</div>
											<Text className="text-xs text-zinc-500 dark:text-zinc-400">
												{cart.items_count}{" "}
												{cart.items_count === 1
													? "ítem"
													: "ítems"}{" "}
												· {cart.total_formatted}
											</Text>
											{cart.type === "lab" &&
												cart.lab_brands?.length > 0 && (
													<div className="flex flex-wrap gap-1">
														{cart.lab_brands.map((brand) => (
															<Badge
																key={brand.value}
																color="slate"
															>
																{brand.label}
															</Badge>
														))}
													</div>
												)}
										</div>
									</TableCell>
										<TableCell>
											<CheckoutSummaryCell cart={cart} />
										</TableCell>
										<TableCell>
											<Badge color={b.color}>{b.label}</Badge>
										</TableCell>
										<TableCell>
											<div className="space-y-0.5">
												<div className="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
													<ClockIcon className="size-4 shrink-0" />
													<span>
														Última: {cart.updated_at_human || "—"}
													</span>
												</div>
												{cart.inactive_for_label ? (
													<Text
														className={clsx(
															"text-sm font-medium tabular-nums",
															cart.display_status === "abandoned"
																? "text-red-600 dark:text-red-300"
																: "text-zinc-900 dark:text-zinc-100",
														)}
													>
														Sin actividad: {cart.inactive_for_label}
													</Text>
												) : cart.display_status === "completed" ? (
													<Text className="text-xs text-zinc-500 dark:text-zinc-400">
														Compra registrada
													</Text>
												) : null}
											</div>
										</TableCell>
									</TableRow>
								);
							})}
						</TableBody>
					</Table>
				</PaginatedTable>
			</div>
		</AdminLayout>
	);
}
