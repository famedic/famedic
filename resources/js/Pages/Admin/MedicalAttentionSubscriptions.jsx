import { useForm } from "@inertiajs/react";
import { useMemo, useState } from "react";

import AdminLayout from "@/Layouts/AdminLayout";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Heading } from "@/Components/Catalyst/heading";
import { Input, InputGroup } from "@/Components/Catalyst/input";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import {
	CheckCircleIcon,
	XCircleIcon,
	ArchiveBoxIcon,
	MagnifyingGlassIcon,
	CalendarDateRangeIcon,
	UserGroupIcon,
	CreditCardIcon,
} from "@heroicons/react/16/solid";

import EmptyListCard from "@/Components/EmptyListCard";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ExportDialog from "@/Components/ExportDialog";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import OdessaLogo from "@/Components/OdessaLogo";
import OdessaBadge from "@/Components/OdessaBadge";
import MedicalAttentionSubscriptionTableRow from "@/Components/MedicalAttentionSubscriptionTableRow";
import ResultsAndExport from "@/Components/ResultsAndExport";

export default function MedicalAttentionSubscriptions({
	subscriptions,
	filters,
	canExport,
}) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		status: filters.status || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		payment_method: filters.payment_method || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.medical-attention-subscriptions.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.status || "") !== (filters.status || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || "") ||
			(data.payment_method || "") !== (filters.payment_method || ""),
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

		if (filters.status === "active") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					activas
				</Badge>,
			);
		} else if (filters.status === "inactive") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					inactivas
				</Badge>,
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

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Membresías médicas">
			<div className="space-y-8">
				<Heading>Membresías médicas</Heading>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<div className="flex-1 md:max-w-md">
							<InputGroup>
								<MagnifyingGlassIcon />
								<Input
									placeholder="Buscar.."
									value={data.search}
									onChange={(e) =>
										setData("search", e.target.value)
									}
								/>
							</InputGroup>
						</div>
						<div className="flex items-center justify-end gap-2">
							<Button
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

				<MedicalAttentionSubscriptionsList
					subscriptions={subscriptions}
					filterBadges={filterBadges}
					filters={filters}
					canExport={canExport}
				/>
			</div>
		</AdminLayout>
	);
}

function Filters({ data, setData, errors }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Estatus"
				value={data.status}
				onChange={(value) => setData("status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="inactive" className="group">
					<XCircleIcon />
					<ListboxLabel>Inactivas</ListboxLabel>
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

function MedicalAttentionSubscriptionsList({
	subscriptions,
	filterBadges,
	filters,
	canExport,
}) {
	if (subscriptions.data.length === 0) return <EmptyListCard />;

	return (
		<>
			<ResultsAndExport
				paginatedData={subscriptions}
				filterBadges={filterBadges}
				canExport={canExport}
				filters={filters}
				exportUrl={route(
					"admin.medical-attention-subscriptions.export",
				)}
				exportTitle="Descargar membresías médicas"
			/>
			<PaginatedTable paginatedData={subscriptions}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Detalles</TableHeader>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Miembros cubiertos</TableHeader>
							<TableHeader className="text-right">
								Vigencia
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{subscriptions.data.map((subscription) => (
							<MedicalAttentionSubscriptionTableRow
								key={subscription.id}
								subscription={subscription}
							/>
						))}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
