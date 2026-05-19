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

function statusBadge(displayStatus) {
	if (displayStatus === "completed") {
		return { color: "blue", label: "Comprado" };
	}
	if (displayStatus === "abandoned") {
		return { color: "red", label: "Abandonado" };
	}
	return { color: "green", label: "Activo" };
}

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
				<Heading>Monitoreo · Carritos</Heading>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por usuario..."
						/>
						<div className="flex items-center justify-end gap-2">
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
								<TableHeader>Ítems</TableHeader>
								<TableHeader>Total</TableHeader>
								<TableHeader>Estatus</TableHeader>
								<TableHeader>Última actividad</TableHeader>
								<TableHeader></TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{carts.data.map((cart) => {
								const b = statusBadge(cart.display_status);
								return (
									<TableRow key={cart.id}>
										<TableCell>
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
										</TableCell>
										<TableCell>
											<div className="flex flex-col gap-1">
												<div className="flex items-center gap-1 text-sm text-zinc-950 dark:text-zinc-100">
													<ShoppingCartIcon className="size-4 text-zinc-400 dark:text-zinc-500" />
													{cart.type_label}
												</div>
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
											<Strong>{cart.items_count}</Strong>
										</TableCell>
										<TableCell>{cart.total_formatted}</TableCell>
										<TableCell>
											<div className="flex flex-wrap gap-1">
												<Badge color={b.color}>{b.label}</Badge>
												{cart.appointment_pending_confirmation && (
													<Badge color="amber">
														Cita por confirmar
													</Badge>
												)}
												{cart.appointment_confirmed_pending_payment && (
													<Badge color="violet">
														Cita confirmada, sin pago
													</Badge>
												)}
											</div>
										</TableCell>
										<TableCell>
											<div className="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
												<ClockIcon className="size-4" />
												{cart.updated_at_human}
											</div>
										</TableCell>
										<TableCell>
											{canViewCartDetails ? (
												<Button
													href={route("admin.carts.show", {
														cart: cart.id,
													})}
													outline
													size="sm"
												>
													Ver detalle
												</Button>
											) : (
												<Text className="text-xs">
													Sin permiso
												</Text>
											)}
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
