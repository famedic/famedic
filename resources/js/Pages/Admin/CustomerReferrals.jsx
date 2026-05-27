import { useMemo, useState } from "react";
import { useForm } from "@inertiajs/react";
import {
	ArrowLeftIcon,
	CalendarDateRangeIcon,
	EnvelopeOpenIcon,
	MagnifyingGlassIcon,
	UserGroupIcon,
} from "@heroicons/react/16/solid";
import {
	Disclosure,
	DisclosureButton,
	DisclosurePanel,
} from "@headlessui/react";
import { ChevronDownIcon } from "@heroicons/react/20/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text, Strong } from "@/Components/Catalyst/text";
import { Avatar } from "@/Components/Catalyst/avatar";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import PaginatedTable from "@/Components/Admin/PaginatedTable";
import EmptyListCard from "@/Components/EmptyListCard";
import DateFilter from "@/Components/Filters/DateFilter";
import CustomerInfo from "@/Components/CustomerInfo";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";

function formatRegistrationDate(user) {
	if (user.customer?.formatted_created_at) {
		return user.customer.formatted_created_at;
	}

	if (!user.created_at) {
		return "—";
	}

	return new Date(user.created_at).toLocaleDateString("es-MX", {
		day: "numeric",
		month: "short",
		year: "numeric",
	});
}

function InviterAvatar({ inviter }) {
	return <Avatar src={inviter.profile_photo_url} className="size-12" />;
}

function customerWithUser(customer, referral) {
	if (!customer) {
		return null;
	}

	if (customer.user) {
		return customer;
	}

	return { ...customer, user: referral };
}

function ReferralRow({ referral }) {
	const customer = customerWithUser(referral.customer, referral);

	if (!customer) {
		return (
			<TableRow>
				<TableCell>
					<div className="flex items-center gap-2">
						<Avatar src={referral.profile_photo_url} className="size-10" />
						<div>
							{(referral.full_name || referral.email) && (
								<Text>
									<Strong>
										{referral.full_name || referral.email}
									</Strong>
								</Text>
							)}
							{referral.email && <Text>{referral.email}</Text>}
						</div>
					</div>
				</TableCell>
				<TableCell className="text-right">
					<Text>{formatRegistrationDate(referral)}</Text>
				</TableCell>
			</TableRow>
		);
	}

	return (
		<TableRow
			href={route("admin.customers.show", customer.id)}
			title={`Cliente #${customer.id}`}
		>
			<TableCell>
				<div className="flex items-center gap-2">
					<Avatar
						src={
							customer.customerable_type ===
							"App\\Models\\FamilyAccount"
								? customer.customerable?.profile_photo_url
								: customer.user?.profile_photo_url
						}
						className="size-10"
					/>
					<CustomerInfo customer={customer} />
				</div>
			</TableCell>
			<TableCell className="text-right">
				<Text>{formatRegistrationDate(referral)}</Text>
			</TableCell>
		</TableRow>
	);
}

function InviterCard({ inviter }) {
	const referrals = inviter.referrals ?? [];
	const customerHref = inviter.customer
		? route("admin.customers.show", inviter.customer.id)
		: route("admin.users.show", inviter.id);

	return (
		<Disclosure
			as="div"
			className="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900"
		>
			<DisclosureButton className="group flex w-full items-center gap-4 p-4 text-left">
				<InviterAvatar inviter={inviter} />
				<div className="min-w-0 flex-1">
					<Text>
						<Strong>{inviter.full_name || inviter.email}</Strong>
					</Text>
					{inviter.email && <Text>{inviter.email}</Text>}
					{inviter.customer && (
						<div className="mt-1">
							<CustomerInfo customer={inviter.customer} />
						</div>
					)}
				</div>
				<Badge color="sky" className="shrink-0">
					<UserGroupIcon className="size-4" />
					{inviter.referrals_count}{" "}
					{inviter.referrals_count === 1 ? "referido" : "referidos"}
				</Badge>
				<Button href={customerHref} outline className="shrink-0 max-md:hidden">
					Ver invitador
				</Button>
				<ChevronDownIcon className="size-5 shrink-0 text-zinc-400 transition group-data-[open]:rotate-180" />
			</DisclosureButton>

			<DisclosurePanel className="border-t border-zinc-200 px-4 pb-4 pt-2 dark:border-zinc-700">
				{referrals.length === 0 ? (
					<Text className="py-4 text-center text-zinc-500">
						Sin registros en el periodo seleccionado.
					</Text>
				) : (
					<Table dense>
						<TableHead>
							<TableRow>
								<TableHeader>Cliente registrado</TableHeader>
								<TableHeader className="text-right">
									Fecha de registro
								</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{referrals.map((referral) => (
								<ReferralRow key={referral.id} referral={referral} />
							))}
						</TableBody>
					</Table>
				)}
			</DisclosurePanel>
		</Disclosure>
	);
}

export default function CustomerReferrals({ inviters, filters, summary }) {
	const { data, setData, get, errors, processing } = useForm({
		search: filters.search || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	const [showFilters, setShowFilters] = useState(false);

	const updateResults = (e) => {
		e.preventDefault();
		if (!processing && showUpdateButton) {
			get(route("admin.customers.referrals"), {
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
				<Badge color="sky">
					<MagnifyingGlassIcon className="size-4" />
					{filters.search}
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

	const hasInviters =
		inviters?.data?.length > 0 || (inviters?.total ?? 0) > 0;

	return (
		<AdminLayout title="Referenciados">
			<div className="space-y-8">
				<div className="flex flex-wrap items-center gap-4">
					<Button
						href={route("admin.customers.index")}
						plain
						className="!px-0"
					>
						<ArrowLeftIcon className="size-4" />
						Clientes
					</Button>
				</div>

				<div>
					<Heading>Referenciados</Heading>
					<Text className="mt-2 max-w-2xl text-zinc-600 dark:text-zinc-400">
						Clientes que invitaron a otros y los registros generados con su
						enlace de invitación.
					</Text>
				</div>

				<div className="grid gap-4 sm:grid-cols-2">
					<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">Invitadores</Text>
						<p className="mt-1 text-2xl font-semibold tabular-nums">
							{summary.inviters_count}
						</p>
					</div>
					<div className="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
						<Text className="text-xs text-zinc-500">
							Registros por invitación
						</Text>
						<p className="mt-1 text-2xl font-semibold tabular-nums">
							{summary.referrals_count}
						</p>
					</div>
				</div>

				<form className="space-y-6" onSubmit={updateResults}>
					<div className="flex flex-col justify-between gap-4 md:flex-row md:items-center">
						<SearchInput
							value={data.search}
							onChange={(value) => setData("search", value)}
							placeholder="Buscar invitador por nombre, correo o teléfono..."
						/>
						<Button
							outline
							type="button"
							onClick={() => setShowFilters(!showFilters)}
						>
							Filtros
							<FilterCountBadge count={filterBadges.length} />
						</Button>
					</div>

					{showFilters && (
						<div className="grid gap-4 md:grid-cols-2">
							<DateFilter
								label="Registros desde"
								value={data.start_date}
								onChange={(value) => setData("start_date", value)}
								error={errors.start_date}
							/>
							<DateFilter
								label="Registros hasta"
								value={data.end_date}
								onChange={(value) => setData("end_date", value)}
								error={errors.end_date}
							/>
						</div>
					)}

					{showUpdateButton && (
						<div className="flex justify-center">
							<UpdateButton type="submit" processing={processing} />
						</div>
					)}
				</form>

				{filterBadges.length > 0 && (
					<div className="flex flex-wrap gap-2">{filterBadges}</div>
				)}

				{!hasInviters ? (
					<EmptyListCard />
				) : (
					<>
						<Subheading className="flex items-center gap-2">
							<EnvelopeOpenIcon className="size-5" />
							Campañas por invitador
						</Subheading>

						<div className="space-y-4">
							{inviters.data.map((inviter) => (
								<InviterCard key={inviter.id} inviter={inviter} />
							))}
						</div>

						{inviters.last_page > 1 && (
							<PaginatedTable paginatedData={inviters} />
						)}
					</>
				)}
			</div>
		</AdminLayout>
	);
}
