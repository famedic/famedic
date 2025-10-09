import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	CheckCircleIcon,
	XCircleIcon,
	ArchiveBoxIcon,
	UserGroupIcon,
	GlobeAltIcon,
	CalendarDateRangeIcon,
	EnvelopeOpenIcon,
	NoSymbolIcon,
	MagnifyingGlassIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Heading } from "@/Components/Catalyst/heading";
import SearchInput from "@/Components/Admin/SearchInput";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { PresentationChartLineIcon } from "@heroicons/react/24/outline";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import EmptyListCard from "@/Components/EmptyListCard";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import DateFilter from "@/Components/Filters/DateFilter";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import ResultsAndExport from "@/Components/ResultsAndExport";
import CustomersChart from "@/Components/CustomersChart";
import OdessaBadge from "@/Components/OdessaBadge";
import OdessaLogo from "@/Components/OdessaLogo";
import {
	RegularAccountBadge,
	FamilyAccountBadge,
} from "@/Components/CustomerAccountBadges";
import CustomerInfo from "@/Components/CustomerInfo";
import ReferralBadge from "@/Components/ReferralBadge";
import MedicalAttentionBadge from "@/Components/MedicalAttentionBadge";

export default function Customers({ customers, chart, filters, canExport }) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		type: filters.type || "",
		medical_attention_status: filters.medical_attention_status || "",
		referral_status: filters.referral_status || "",
		verification_status: filters.verification_status || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);
	const [showChart, setShowChart] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.customers.index"), {
				replace: true,
				preserveState: true,
			});
		}
	};

	const showUpdateButton = useMemo(
		() =>
			(data.search || "") !== (filters.search || "") ||
			(data.type || "") !== (filters.type || "") ||
			(data.medical_attention_status || "") !==
				(filters.medical_attention_status || "") ||
			(data.referral_status || "") !== (filters.referral_status || "") ||
			(data.verification_status || "") !==
				(filters.verification_status || "") ||
			(data.start_date || "") !== (filters.start_date || "") ||
			(data.end_date || "") !== (filters.end_date || ""),
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

		if (filters.type === "regular") {
			badges.push(<RegularAccountBadge key="regular" />);
		} else if (filters.type === "odessa") {
			badges.push(<OdessaBadge key="odessa">ODESSA</OdessaBadge>);
		} else if (filters.type === "familiar") {
			badges.push(<FamilyAccountBadge key="family" />);
		}

		if (filters.medical_attention_status === "active") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					membresía médica activa
				</Badge>,
			);
		} else if (filters.medical_attention_status === "inactive") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					membresía médica inactiva
				</Badge>,
			);
		}

		if (filters.referral_status === "referred") {
			badges.push(
				<Badge color="slate">
					<EnvelopeOpenIcon className="size-4" />
					referenciado
				</Badge>,
			);
		} else if (filters.referral_status === "not_referred") {
			badges.push(
				<Badge color="slate">
					<NoSymbolIcon className="size-4" />
					no referenciado
				</Badge>,
			);
		}

		if (filters.verification_status === "verified") {
			badges.push(
				<Badge color="famedic-lime">
					<CheckCircleIcon className="size-4" />
					verificados
				</Badge>,
			);
		} else if (filters.verification_status === "unverified") {
			badges.push(
				<Badge color="red">
					<XCircleIcon className="size-4" />
					no verificados
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

		return badges;
	}, [filters]);

	return (
		<AdminLayout title="Clientes">
			<Heading>Clientes</Heading>

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
							Filtros
							<FilterCountBadge count={filterBadges.length} />
						</Button>
						<Button
							outline
							className="w-full"
							onClick={() => setShowChart(!showChart)}
						>
							Gráfica
							<PresentationChartLineIcon className="" />
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

			{showChart && <CustomersChart chart={chart} />}

			<CustomersList
				customers={customers}
				filters={filters}
				filterBadges={filterBadges}
				canExport={canExport}
			/>
		</AdminLayout>
	);
}

function Filters({ data, setData, errors }) {
	return (
		<div className="grid gap-4 md:grid-cols-3">
			<ListboxFilter
				label="Tipo de cuenta"
				value={data.type}
				onChange={(value) => setData("type", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="regular" className="group">
					<GlobeAltIcon />
					<ListboxLabel>Regular</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="odessa" className="group">
					<OdessaLogo className="size-4" />
					<ListboxLabel>ODESSA</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="familiar" className="group">
					<UserGroupIcon />
					<ListboxLabel>Familiar</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Suscripción médica"
				value={data.medical_attention_status}
				onChange={(value) => setData("medical_attention_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="active" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Activa</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="inactive" className="group">
					<XCircleIcon />
					<ListboxLabel>Inactiva</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Referenciado"
				value={data.referral_status}
				onChange={(value) => setData("referral_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="referred" className="group">
					<EnvelopeOpenIcon />
					<ListboxLabel>Referenciado</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="not_referred" className="group">
					<NoSymbolIcon />
					<ListboxLabel>No referenciado</ListboxLabel>
				</ListboxOption>
			</ListboxFilter>
			<ListboxFilter
				label="Verificación"
				value={data.verification_status}
				onChange={(value) => setData("verification_status", value)}
			>
				<ListboxOption value="" className="group">
					<ArchiveBoxIcon />
					<ListboxLabel>Todos</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="verified" className="group">
					<CheckCircleIcon />
					<ListboxLabel>Verificados</ListboxLabel>
				</ListboxOption>
				<ListboxOption value="unverified" className="group">
					<XCircleIcon />
					<ListboxLabel>No verificados</ListboxLabel>
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

function CustomersList({ customers, filters, filterBadges, canExport }) {
	if (!customers || !customers.data || customers.data.length === 0) {
		return <EmptyListCard />;
	}

	return (
		<>
			<ResultsAndExport
				paginatedData={customers}
				filterBadges={filterBadges}
				canExport={canExport}
				filters={filters}
				exportUrl={route("admin.customers.export")}
				exportTitle="Descargar clientes"
			/>

			<PaginatedTable paginatedData={customers}>
				<Table className="[--gutter:theme(spacing.6)]">
					<TableHead>
						<TableRow>
							<TableHeader>Cliente</TableHeader>
							<TableHeader>Membresía médica</TableHeader>
							<TableHeader>Laboratorio</TableHeader>
							<TableHeader>Farmacia</TableHeader>
							<TableHeader className="text-right">
								Fecha de registro
							</TableHeader>
						</TableRow>
					</TableHead>
					<TableBody>
						{customers.data.map((customer) => {
							return (
								<TableRow
									key={customer.id}
									href={route(
										"admin.customers.show",
										customer.id,
									)}
									title={`Cliente #${
										customer.customerable_type ===
										"App\\Models\\FamilyAccount"
											? customer.customerable
													?.full_name || "Sin nombre"
											: customer.user?.full_name ||
												customer.user?.email ||
												"Sin nombre"
									}`}
									dusk={`showCustomer-${customer.id}`}
								>
									<TableCell>
										<div className="flex items-center gap-2">
											<Avatar
												src={
													customer.customerable_type ===
													"App\\Models\\FamilyAccount"
														? customer.customerable
																?.profile_photo_url
														: customer.user
																?.profile_photo_url
												}
												className="size-12"
											/>
											<CustomerInfo customer={customer} />
										</div>
									</TableCell>
									<TableCell>
										<div className="space-y-1">
											{customer.medical_attention_identifier && (
												<div>
													<MedicalAttentionBadge
														isActive={
															customer.medical_attention_subscription_is_active
														}
													>
														{
															customer.medical_attention_identifier
														}
													</MedicalAttentionBadge>
												</div>
											)}
											{customer.medical_attention_subscription_is_active &&
												customer.formatted_medical_attention_subscription_expires_at && (
													<Text className="!text-xs">
														Expira{" "}
														{
															customer.formatted_medical_attention_subscription_expires_at
														}
													</Text>
												)}
											{customer.family_accounts_count >
												0 && (
												<Text className="mt-1 !text-xs">
													{
														customer.family_accounts_count
													}{" "}
													familiar
													{customer.family_accounts_count !==
													1
														? "es"
														: ""}
												</Text>
											)}
										</div>
									</TableCell>
									<TableCell>
										<div>
											<Text>
												<Strong>
													{customer.laboratory_purchases_count ||
														0}
												</Strong>{" "}
												pedidos
											</Text>
											{customer.laboratory_purchases_sum_total_cents >
												0 && (
												<Text>
													$
													{(
														customer.laboratory_purchases_sum_total_cents /
														100
													).toLocaleString(
														"es-MX",
													)}{" "}
													MXN
												</Text>
											)}
										</div>
									</TableCell>
									<TableCell>
										<div>
											<Text>
												<Strong>
													{customer.online_pharmacy_purchases_count ||
														0}
												</Strong>{" "}
												pedidos
											</Text>
											{customer.online_pharmacy_purchases_sum_total_cents >
												0 && (
												<Text>
													$
													{(
														customer.online_pharmacy_purchases_sum_total_cents /
														100
													).toLocaleString(
														"es-MX",
													)}{" "}
													MXN
												</Text>
											)}
										</div>
									</TableCell>
									<TableCell className="text-right">
										<div className="space-y-1">
											<Text>
												{customer.formatted_created_at}
											</Text>
											{customer.user?.referred_by && (
												<div className="flex justify-end">
													<ReferralBadge
														customer={customer}
														truncate={true}
													/>
												</div>
											)}
										</div>
									</TableCell>
								</TableRow>
							);
						})}
					</TableBody>
				</Table>
			</PaginatedTable>
		</>
	);
}
