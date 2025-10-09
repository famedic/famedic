import { useState, useMemo } from "react";
import { useForm } from "@inertiajs/react";
import {
	MagnifyingGlassIcon,
	ClockIcon,
	CheckCircleIcon,
	ArchiveBoxIcon,
} from "@heroicons/react/16/solid";
import { BuildingStorefrontIcon } from "@heroicons/react/24/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { Avatar } from "@/Components/Catalyst/avatar";
import {
	ListboxOption,
	ListboxLabel,
} from "@/Components/Catalyst/listbox";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import SearchInput from "@/Components/Admin/SearchInput";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import LaboratoryBrandCard from "@/Components/LaboratoryBrandCard";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import StatusBadge from "@/Components/StatusBadge";

export default function LaboratoryAppointments({
	laboratoryAppointments,
	filters,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		completed: filters.completed || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.laboratory-appointments.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.completed || "") !== (filters.completed || ""),
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

		if (filters.completed === "false") {
			badges.push(
				<Badge color="slate">
					<ClockIcon className="size-4" />
					solicitadas
				</Badge>,
			);
		} else if (filters.completed === "true") {
			badges.push(
				<StatusBadge 
					isActive={true} 
					activeText="confirmadas" 
				/>,
			);
		}

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Citas de laboratorio">
			<div className="space-y-8">
				<Heading>Citas de laboratorio</Heading>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar citas..."
						/>
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

				<LaboratoryAppointmentsList
					laboratoryAppointments={laboratoryAppointments}
					filters={filters}
					filterBadges={filterBadges}
				/>
			</div>
		</AdminLayout>
	);
}

function Filters({ data, setData }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Estado"
				value={data.completed}
				onChange={(value) => setData("completed", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<ClockIcon />
					<ListboxLabel>Solicitadas</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Confirmadas</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
		</div>
	);
}

function LaboratoryAppointmentsList({
	laboratoryAppointments,
	filters,
	filterBadges,
}) {
	if (laboratoryAppointments.data.length === 0) return <EmptyListCard />;

	return (
		<>
			<SearchResultsWithFilters
				paginatedData={laboratoryAppointments}
				filterBadges={filterBadges}
			/>

			<PaginatedTable paginatedData={laboratoryAppointments}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Cita</TableHeader>
							<TableHeader>Laboratorio</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{laboratoryAppointments.data.map(
							(laboratoryAppointment) => (
								<TableRow
									key={laboratoryAppointment.id}
									href={route(
										"admin.laboratory-appointments.show",
										laboratoryAppointment.id,
									)}
									title={`Cita #${laboratoryAppointment.id}`}
									dusk={`editLaboratoryAppointment-${laboratoryAppointment.id}`}
								>
									<TableCell>
										<div className="flex items-center gap-2">
											<Avatar
												src={
													laboratoryAppointment
														.customer.user
														.profile_photo_url
												}
												className="size-12"
											/>
											<div>
												{laboratoryAppointment.confirmed_at ? (
													<Badge color="famedic-lime">
														<CheckCircleIcon className="size-3" />
														<span className="text-xs">
															Confirmada{" "}
															{
																laboratoryAppointment.formatted_confirmed_at ||
																laboratoryAppointment.formatted_created_at
															}
														</span>
													</Badge>
												) : (
													<Badge color="slate">
														<ClockIcon className="size-3 fill-famedic-dark dark:fill-famedic-light" />
														<span className="text-xs">
															Solicitada{" "}
															{
																laboratoryAppointment.formatted_created_at
															}
														</span>
													</Badge>
												)}
												<Text>
													<Strong>
														{laboratoryAppointment.patient_full_name ||
															laboratoryAppointment
																.customer.user
																.full_name}
													</Strong>
												</Text>
												<Text>
													{
														laboratoryAppointment
															.customer.user.email
													}
												</Text>
											</div>
										</div>
									</TableCell>

									<TableCell>
										<Text>
											{
												laboratoryAppointment.formatted_appointment_date
											}
										</Text>
										{laboratoryAppointment.laboratory_store && (
											<Badge color="slate">
												<BuildingStorefrontIcon className="size-3 fill-famedic-dark dark:fill-famedic-light" />
												<Text>
													<span className="text-xs">
														{
															laboratoryAppointment
																.laboratory_store
																.name
														}
													</span>
												</Text>
											</Badge>
										)}
									</TableCell>

									<TableCell className="text-left">
										<LaboratoryBrandCard
											className="w-40 p-4"
											src={
												"/images/gda/GDA-" +
												laboratoryAppointment.brand.toUpperCase() +
												".png"
											}
										/>
									</TableCell>
								</TableRow>
							),
						)}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
