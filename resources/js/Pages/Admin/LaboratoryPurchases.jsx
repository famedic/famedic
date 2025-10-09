import AdminLayout from "@/Layouts/AdminLayout";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { useForm } from "@inertiajs/react";
import { useMemo, useState } from "react";
import {
	Table,
	TableBody,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	ArchiveBoxIcon,
	CalendarDateRangeIcon,
	CheckCircleIcon,
	XCircleIcon,
	ClockIcon,
	CreditCardIcon,
	MagnifyingGlassIcon,
	CommandLineIcon,
} from "@heroicons/react/16/solid";
import {
	FunnelIcon,
	PresentationChartLineIcon,
} from "@heroicons/react/24/outline";
import EmptyListCard from "@/Components/EmptyListCard";
import LaboratoryPurchaseTableRow from "@/Components/LaboratoryPurchaseTableRow";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PurchasesChart from "@/Components/PurchasesChart";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ResultsAndExport from "@/Components/ResultsAndExport";
import { Heading } from "@/Components/Catalyst/heading";
import SearchInput from "@/Components/Admin/SearchInput";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import OdessaLogo from "@/Components/OdessaLogo";
import OdessaBadge from "@/Components/OdessaBadge";
import StatusBadge from "@/Components/StatusBadge";

export default function LaboratoryPurchases({
	laboratoryPurchases,
	chart,
	filters,
	brands,
	canExport,
}) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		deleted: filters.deleted || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		invoice_requested: filters.invoice_requested || "",
		results_uploaded: filters.results_uploaded || "",
		invoice_uploaded: filters.invoice_uploaded || "",
		payment_method: filters.payment_method || "",
		brand: filters.brand || "",
		dev_assistance: filters.dev_assistance || "",
	});

	const [showChart, setShowChart] = useState(false);
	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-purchases.index"), {
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.deleted || "") !== (filters.deleted || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || "") ||
			(data.invoice_requested || "") !==
				(filters.invoice_requested || "") ||
			(data.results_uploaded || "") !==
				(filters.results_uploaded || "") ||
			(data.invoice_uploaded || "") !==
				(filters.invoice_uploaded || "") ||
			(data.payment_method || "") !== (filters.payment_method || "") ||
			(data.brand || "") !== (filters.brand || "") ||
			(data.dev_assistance || "") !== (filters.dev_assistance || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.deleted === "false") {
			badges.push(<StatusBadge isActive={true} activeText="activos" />);
		} else if (filters.deleted === "true") {
			badges.push(
				<StatusBadge
					isActive={false}
					inactiveText="cancelados"
					inactiveColor="red"
				/>,
			);
		}

		if (filters.start_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					desde {filters.formatted_start_date}
				</Badge>,
			);
		}

		if (filters.end_date) {
			badges.push(
				<Badge color="slate">
					<CalendarDateRangeIcon className="size-4" />
					hasta {filters.formatted_end_date}
				</Badge>,
			);
		}

		if (filters.invoice_requested === "true") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					factura solicitada
				</Badge>,
			);
		} else if (filters.invoice_requested === "false") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					factura no solicitada
				</Badge>,
			);
		}

		if (filters.invoice_uploaded === "true") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					factura completada
				</Badge>,
			);
		} else if (filters.invoice_uploaded === "false") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					factura pendiente
				</Badge>,
			);
		}

		if (filters.results_uploaded === "true") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					resultados completados
				</Badge>,
			);
		} else if (filters.results_uploaded === "false") {
			badges.push(
				<Badge color="slate">
					<ClockIcon className="size-4" />
					resultados pendientes
				</Badge>,
			);
		}

		if (filters.payment_method === "odessa") {
			badges.push(<OdessaBadge>Caja de ahorro</OdessaBadge>);
		} else if (filters.payment_method === "stripe") {
			badges.push(
				<Badge color="slate">
					<CreditCardIcon className="size-4" />
					Pago con tarjeta
				</Badge>,
			);
		}

		if (filters.brand) {
			badges.push(
				<LaboratoryBrandCard
					src={`/images/gda/${brands[filters.brand].imageSrc}`}
					className="w-12"
				/>,
			);
		}

		if (filters.dev_assistance === "with_requests") {
			badges.push(
				<Badge color="slate">
					<CommandLineIcon className="size-4" />
					con solicitudes
				</Badge>,
			);
		} else if (filters.dev_assistance === "with_open_requests") {
			badges.push(
				<Badge color="red">
					<CommandLineIcon className="size-4 animate-pulse" />
					con solicitudes abiertas
				</Badge>,
			);
		} else if (filters.dev_assistance === "no_requests") {
			badges.push(
				<Badge color="green">
					<CheckCircleIcon className="size-4" />
					sin solicitudes
				</Badge>,
			);
		}

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Pedidos de laboratorio">
			<Heading>Pedidos de laboratorio</Heading>

			<form className="space-y-8" onSubmit={updateResults}>
				<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
					/>
					<div className="flex items-center justify-end gap-2">
						<Button
							outline
							className="w-full"
							onClick={() => setShowFilters(!showFilters)}
						>
							{filterBadges.length ? (
								<FilterCountBadge count={filterBadges.length} />
							) : (
								<FunnelIcon />
							)}
							Filtros
						</Button>
						<Button
							outline
							className="w-full"
							onClick={() => setShowChart(!showChart)}
						>
							<PresentationChartLineIcon />
							Gráfica
						</Button>
					</div>
				</div>

				{showFilters && (
					<Filters
						data={data}
						setData={setData}
						errors={errors}
						brands={brands}
					/>
				)}

				{showUpdateButton && (
					<div className="flex justify-center">
						<UpdateButton type="submit" processing={processing} />
					</div>
				)}
			</form>

			{showChart && <PurchasesChart chart={chart} />}

			<LaboratoryPurchasesList
				laboratoryPurchases={laboratoryPurchases}
				filters={filters}
				filterBadges={filterBadges}
				canExport={canExport}
			/>
		</AdminLayout>
	);
}

function Filters({ data, setData, errors, brands }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Marca"
				placeholder="Marca"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<ListboxOption value="">
					<ListboxLabel>Todas las marcas</ListboxLabel>
				</ListboxOption>
				{Object.entries(brands || {}).map(([key, brand]) => (
					<ListboxOption key={key} value={key}>
						<div className="flex w-full items-center justify-between gap-2">
							<ListboxLabel>{brand.name}</ListboxLabel>
							<LaboratoryBrandCard
								src={`/images/gda/${brand.imageSrc}`}
								className="w-12"
							/>
						</div>
					</ListboxOption>
				))}
			</ListboxFilter>
			<ListboxFilter
				label="Estatus"
				value={data.deleted}
				onChange={(value) => setData("deleted", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<XCircleIcon />
					<ListboxLabel>Cancelados</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Solicitud de factura"
				value={data.invoice_requested}
				onChange={(value) => setData("invoice_requested", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Solicitada</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<XCircleIcon />
					<ListboxLabel>No solicitada</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Carga de factura"
				value={data.invoice_uploaded}
				onChange={(value) => setData("invoice_uploaded", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Completada</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<XCircleIcon />
					<ListboxLabel>Pendiente</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Carga de resultados"
				value={data.results_uploaded}
				onChange={(value) => setData("results_uploaded", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Completada</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<ClockIcon />
					<ListboxLabel>Pendiente</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Método de pago"
				value={data.payment_method}
				onChange={(value) => setData("payment_method", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="odessa" className="group">
					<OdessaLogo className="size-4" />
					<ListboxLabel>Caja de ahorro</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="stripe" className="group">
					<CreditCardIcon />
					<ListboxLabel>Pago con tarjeta</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Asistencia técnica"
				value={data.dev_assistance}
				onChange={(value) => setData("dev_assistance", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="with_requests" className="group">
					<CommandLineIcon />
					<ListboxLabel>Con solicitudes</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="with_open_requests" className="group">
					<CommandLineIcon />
					<ListboxLabel>Con solicitudes abiertas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="no_requests" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Sin solicitudes</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
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

function LaboratoryPurchasesList({
	laboratoryPurchases,
	filterBadges,
	filters,
	canExport,
}) {
	if (laboratoryPurchases.data.length === 0) return <EmptyListCard />;

	return (
		<>
			<ResultsAndExport
				paginatedData={laboratoryPurchases}
				filterBadges={filterBadges}
				canExport={canExport}
				filters={filters}
				exportUrl={route("admin.laboratory-purchases.export")}
				exportTitle="Descargar pedidos de laboratorio"
			/>
			<PaginatedTable paginatedData={laboratoryPurchases}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Detalles</TableHeader>
							<TableHeader>Paciente</TableHeader>
							<TableHeader>Marca</TableHeader>
							<TableHeader className="text-right">
								Detalles adicionales
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{laboratoryPurchases.data.map((laboratoryPurchase) => (
							<LaboratoryPurchaseTableRow
								key={laboratoryPurchase.id}
								laboratoryPurchase={laboratoryPurchase}
								showBrand={true}
							/>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
