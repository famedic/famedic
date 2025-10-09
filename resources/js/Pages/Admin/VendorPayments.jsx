import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	MagnifyingGlassIcon,
	CalendarDateRangeIcon,
	ArrowDownTrayIcon,
} from "@heroicons/react/16/solid";
import { PlusIcon } from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableHead,
	TableHeader,
	TableRow,
	TableCell,
} from "@/Components/Catalyst/table";
import { Text, Strong, Anchor } from "@/Components/Catalyst/text";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import EmptyListCard from "@/Components/EmptyListCard";
import SearchResultsMessage from "@/Components/SearchResultsMessage";

function resolveContext() {
	const current = route().current();

	const contexts = {
		"admin.online-pharmacy-purchases.vendor-payments.index": {
			title: "Pagos a Vitau",
			vendorLabel: "Vitau",
			routes: {
				index: "admin.online-pharmacy-purchases.vendor-payments.index",
				create: "admin.online-pharmacy-purchases.vendor-payments.create",
				show: "admin.online-pharmacy-purchases.vendor-payments.show",
			},
			selectors: {
				count: (vp) => vp.online_pharmacy_purchases_count ?? 0,
				purchases: (vp) => vp.online_pharmacy_purchases ?? [],
				orderId: (p) => p.vitau_order_id,
			},
		},
		"admin.laboratory-purchases.vendor-payments.index": {
			title: "Pagos a GDA",
			vendorLabel: "GDA",
			routes: {
				index: "admin.laboratory-purchases.vendor-payments.index",
				create: "admin.laboratory-purchases.vendor-payments.create",
				show: "admin.laboratory-purchases.vendor-payments.show",
			},
			selectors: {
				count: (vp) => vp.laboratory_purchases_count ?? 0,
				purchases: (vp) => vp.laboratory_purchases ?? [],
				orderId: (p) => p.gda_order_id,
			},
		},
	};

	return contexts[current] ?? null;
}

export default function VendorPayments({ vendorPayments, filters }) {
	const ctx = resolveContext();

	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton && ctx) {
			get(route(ctx.routes.index), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge key="search" color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.start_date) {
			badges.push(
				<Badge key="start_date" color="slate">
					<CalendarDateRangeIcon className="size-4" />
					desde {filters.formatted_start_date}
				</Badge>,
			);
		}

		if (filters.end_date) {
			badges.push(
				<Badge key="end_date" color="slate">
					<CalendarDateRangeIcon className="size-4" />
					hasta {filters.formatted_end_date}
				</Badge>,
			);
		}

		return badges;
	}, [filters]);

	if (
		!vendorPayments ||
		!vendorPayments.data ||
		vendorPayments.data.length === 0
	) {
		return (
			<AdminLayout title={ctx ? ctx.title : "Pagos"}>
				<div className="flex items-center justify-between">
					<Heading>{ctx ? ctx.title : "Pagos"}</Heading>
					{ctx && (
						<Button href={route(ctx.routes.create)} outline>
							<PlusIcon />
							Crear pago
						</Button>
					)}
				</div>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por identificador de orden..."
						/>
						<div className="flex items-center justify-end gap-2">
							<Button
								type="button"
								outline
								className="w-full"
								onClick={() => setShowFilters(!showFilters)}
							>
								Filtros
								<FilterCountBadge count={filterBadges.length} />
							</Button>
						</div>
					</div>

					{showFilters && (
						<Filters
							data={data}
							setData={setData}
							errors={errors}
						/>
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton
								type="submit"
								processing={processing}
							/>
						</div>
					)}
				</form>

				<EmptyListCard />
			</AdminLayout>
		);
	}

	return (
		<AdminLayout title={ctx ? ctx.title : "Pagos"}>
			<div className="flex items-center justify-between">
				<Heading>{ctx ? ctx.title : "Pagos"}</Heading>
				{ctx && (
					<Button href={route(ctx.routes.create)} outline>
						<PlusIcon />
						Crear pago
					</Button>
				)}
			</div>

			<form className="space-y-8" onSubmit={updateResults}>
				<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
						placeholder="Buscar por identificador de orden..."
					/>
					<div className="flex items-center justify-end gap-2">
						<Button
							type="button"
							outline
							className="w-full"
							onClick={() => setShowFilters(!showFilters)}
						>
							Filtros
							<FilterCountBadge count={filterBadges.length} />
						</Button>
					</div>
				</div>

				{showFilters && (
					<Filters data={data} setData={setData} errors={errors} />
				)}

				{showUpdateButton && (
					<div className="flex justify-center">
						<UpdateButton type="submit" processing={processing} />
					</div>
				)}
			</form>

			<SearchResultsMessage paginatedData={vendorPayments} />

			{filterBadges.length > 0 && (
				<div className="flex flex-wrap gap-2">{filterBadges}</div>
			)}

			<PaginatedTable paginatedData={vendorPayments}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Pago</TableHeader>
							<TableHeader>Órdenes</TableHeader>
							<TableHeader>Monto esperado</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{vendorPayments.data.map((vp) => (
							<TableRow
								key={vp.id}
								href={
									ctx
										? route(ctx.routes.show, {
												vendor_payment: vp.id,
											})
										: undefined
								}
								title={
									ctx
										? `Pago a ${ctx.vendorLabel} del ${vp.formatted_paid_at}`
										: vp.formatted_paid_at
								}
							>
								<TableCell>
									<div className="space-y-2">
										<Text>{vp.formatted_paid_at}</Text>
										<div className="relative isolate">
											<Anchor
												href={route("vendor-payment", {
													vendor_payment: vp.id,
												})}
												target="_blank"
												rel="noopener noreferrer"
												onClick={(e) =>
													e.stopPropagation()
												}
												className="inline-flex items-center"
											>
												<ArrowDownTrayIcon className="size-4" />
												Ver comprobante
											</Anchor>
										</div>
									</div>
								</TableCell>
								<TableCell>
									<div className="space-y-2">
										<Text>
											<Strong>
												{ctx
													? ctx.selectors.count(vp)
													: 0}
											</Strong>{" "}
											compra
											{ctx &&
											ctx.selectors.count(vp) !== 1
												? "s"
												: ""}
										</Text>
										<div className="flex flex-wrap gap-2">
											{ctx
												? ctx.selectors
														.purchases(vp)
														.map((p) => (
															<Badge
																key={p.id}
																color="slate"
															>
																{ctx.selectors.orderId(
																	p,
																)}
															</Badge>
														))
												: null}
										</div>
									</div>
								</TableCell>
								<TableCell>
									<Text>{vp.formatted_total}</Text>
								</TableCell>
							</TableRow>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</AdminLayout>
	);
}

function Filters({ data, setData, errors }) {
	return (
		<div className="grid gap-4 md:grid-cols-2">
			<DateFilter
				label="Desde"
				value={data.start_date}
				onChange={(value) => setData("start_date", value)}
				error={errors.start_date}
			/>
			<DateFilter
				label="Hasta"
				value={data.end_date}
				onChange={(value) => setData("end_date", value)}
				error={errors.end_date}
			/>
		</div>
	);
}
