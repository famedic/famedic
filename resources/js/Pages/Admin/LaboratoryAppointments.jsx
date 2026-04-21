import { useState, useMemo } from "react";
import { useForm } from "@inertiajs/react";
import {
	MagnifyingGlassIcon,
	ClockIcon,
	CheckCircleIcon,
	ArchiveBoxIcon,
	CalendarDaysIcon,
	PhoneIcon,
	ChatBubbleLeftRightIcon,
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
	brands,
}) {
	const { data, setData, get, processing } = useForm({
		search: filters.search || "",
		completed: filters.completed || "",
		date_range: filters.date_range || "",
		brand: filters.brand || "",
		phone_call_intent: filters.phone_call_intent || "",
		callback_info: filters.callback_info || "",
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
			(data.completed || "") !== (filters.completed || "") ||
			(data.date_range || "") !== (filters.date_range || "") ||
			(data.brand || "") !== (filters.brand || "") ||
			(data.phone_call_intent || "") !== (filters.phone_call_intent || "") ||
			(data.callback_info || "") !== (filters.callback_info || ""),
		[data, filters],
	);

	const filterBadges = useMemo(() => {
		const badges = [];

		if (filters.search) {
			badges.push(
				<Badge color="sky" key={`search-${filters.search}`}>
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
				</Badge>,
			);
		}

		if (filters.completed === "false") {
			badges.push(
				<Badge color="slate" key="completed-false">
					<ClockIcon className="size-4" />
					solicitadas
				</Badge>,
			);
		} else if (filters.completed === "true") {
			badges.push(
				<StatusBadge
					key="completed-true"
					isActive={true}
					activeText="confirmadas"
				/>,
			);
		}

		if (filters.date_range === "today") {
			badges.push(
				<Badge color="sky" key="range-today">
					<CalendarDaysIcon className="size-4" />
					Citas de hoy
				</Badge>,
			);
		} else if (filters.date_range === "last_7_days") {
			badges.push(
				<Badge color="sky" key="range-last-7-days">
					<CalendarDaysIcon className="size-4" />
					Últimos 7 días
				</Badge>,
			);
		} else if (filters.date_range === "last_6_months") {
			badges.push(
				<Badge color="sky" key="range-last-6-months">
					<CalendarDaysIcon className="size-4" />
					Últimos 6 meses
				</Badge>,
			);
		}

		if (filters.brand) {
			const brandLabel =
				brands?.find((brand) => brand.value === filters.brand)?.label ||
				filters.brand;

			badges.push(
				<Badge color="famedic-lime" key={`brand-${filters.brand}`}>
					{brandLabel}
				</Badge>,
			);
		}

		if (filters.phone_call_intent === "true") {
			badges.push(
				<Badge color="emerald" key="phone-intent-true">
					<PhoneIcon className="size-4" />
					Intentó llamar
				</Badge>,
			);
		} else if (filters.phone_call_intent === "false") {
			badges.push(
				<Badge color="slate" key="phone-intent-false">
					<PhoneIcon className="size-4" />
					No intentó llamar
				</Badge>,
			);
		}

		if (filters.callback_info === "true") {
			badges.push(
				<Badge color="emerald" key="callback-info-true">
					<ChatBubbleLeftRightIcon className="size-4" />
					Dejó info de llamada
				</Badge>,
			);
		} else if (filters.callback_info === "false") {
			badges.push(
				<Badge color="slate" key="callback-info-false">
					<ChatBubbleLeftRightIcon className="size-4" />
					Sin info de llamada
				</Badge>,
			);
		}

		return badges;
	}, [filters, brands]);

	return (
		<AdminLayout title="Citas de laboratorio">
			<div className="space-y-8">
				<Heading>Citas de laboratorio</Heading>

				<form className="space-y-8" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-8 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar por nombre, apellidos, correo o teléfono del paciente/usuario..."
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
							brands={brands}
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

function Filters({ data, setData, brands }) {
	return (
		<div className="grid gap-4 md:grid-cols-3 lg:grid-cols-4">
			<ListboxFilter
				label="Rango de fechas"
				value={data.date_range}
				onChange={(value) => setData("date_range", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="today" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Citas de hoy</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_7_days" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 7 días</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="last_6_months" className="group">
					<CalendarDaysIcon />
					<ListboxLabel>Últimos 6 meses</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>

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

			<ListboxFilter
				label="Marca de laboratorio"
				value={data.brand}
				onChange={(value) => setData("brand", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todas</ListboxLabel>
				</ListboxOption>
				{(brands || []).map((brand) => (
					<ListboxOption
						key={brand.value}
						value={brand.value}
						className="group"
					>
						<BuildingStorefrontIcon />
						<ListboxLabel>{brand.label}</ListboxLabel>
					</ListboxOption>
				))}
			</ListboxFilter>

			<ListboxFilter
				label="Intento de llamada"
				value={data.phone_call_intent}
				onChange={(value) => setData("phone_call_intent", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<PhoneIcon />
					<ListboxLabel>Sí intentó llamar</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<PhoneIcon />
					<ListboxLabel>No intentó llamar</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>

			<ListboxFilter
				label="Información para devolución de llamada"
				value={data.callback_info}
				onChange={(value) => setData("callback_info", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="true" className="group">
					<ChatBubbleLeftRightIcon />
					<ListboxLabel>Dejó información</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="false" className="group">
					<ChatBubbleLeftRightIcon />
					<ListboxLabel>No dejó información</ListboxLabel>
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
							<TableHeader>Intentó llamar</TableHeader>
							<TableHeader>Pref. llamada</TableHeader>
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

									<TableCell>
										{laboratoryAppointment.formatted_phone_call_intent_at ? (
											<Text className="text-sm">
												{
													laboratoryAppointment.formatted_phone_call_intent_at
												}
											</Text>
										) : (
											<Text className="text-sm text-zinc-400">
												—
											</Text>
										)}
									</TableCell>

									<TableCell>
										{laboratoryAppointment.has_left_callback_info ? (
											<Badge color="emerald">Sí</Badge>
										) : (
											<Text className="text-sm text-zinc-400">
												—
											</Text>
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
