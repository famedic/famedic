import { useMemo, useState, useRef, useEffect } from "react";
import { useForm } from "@inertiajs/react";
import {
	MagnifyingGlassIcon,
	CalendarDateRangeIcon,
} from "@heroicons/react/16/solid";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import SearchInput from "@/Components/Admin/SearchInput";
import UpdateButton from "@/Components/Admin/UpdateButton";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import DateFilter from "@/Components/Filters/DateFilter";
import VendorPaymentForm from "@/Components/VendorPaymentForm";
import VendorPaymentBanner from "@/Components/VendorPaymentBanner";
import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";

export default function VendorPaymentLayout({
	heading,
	filters,
	purchases,
	selectedPurchasesDetails,
	selectedPurchasesIds,
	setSelectedPurchasesIds,
	vendorPayment,
	children,
}) {
	const [showFilters, setShowFilters] = useState(false);
	const [showDialog, setShowDialog] = useState(false);

	const selectedPurchasesIdsRef = useRef(selectedPurchasesIds);

	useEffect(() => {
		selectedPurchasesIdsRef.current = selectedPurchasesIds;
	}, [selectedPurchasesIds]);

	const { data, setData, get, errors, processing, transform } = useForm({
		search: filters.search || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
	});

	transform((data) => ({
		...data,
		purchase_ids: selectedPurchasesIdsRef.current,
	}));

	const getCurrentRoute = () => {
		const currentRoute = route().current();

		const routeMap = {
			"admin.laboratory-purchases.vendor-payments.create": () =>
				route("admin.laboratory-purchases.vendor-payments.create"),
			"admin.online-pharmacy-purchases.vendor-payments.create": () =>
				route("admin.online-pharmacy-purchases.vendor-payments.create"),
			"admin.laboratory-purchases.vendor-payments.edit": () =>
				route(
					"admin.laboratory-purchases.vendor-payments.edit",
					vendorPayment.id,
				),
			"admin.online-pharmacy-purchases.vendor-payments.edit": () =>
				route(
					"admin.online-pharmacy-purchases.vendor-payments.edit",
					vendorPayment.id,
				),
		};

		return routeMap[currentRoute]?.() ?? null;
	};

	const updateResults = (e) => {
		e.preventDefault();

		if (!processing && showUpdateButton) {
			const currentRoute = getCurrentRoute();
			if (currentRoute) {
				get(currentRoute, {
					replace: true,
					preserveState: true,
				});
			}
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

	const handleBannerContinue = () => {
		setShowDialog(true);
	};

	const handleSelectedPurchasesIdsChange = (newSelectedPurchasesIds) => {
		selectedPurchasesIdsRef.current = newSelectedPurchasesIds;
		setSelectedPurchasesIds(newSelectedPurchasesIds);
		const currentRoute = getCurrentRoute();
		if (currentRoute) {
			get(currentRoute, {
				replace: true,
				preserveScroll: true,
			});
		}
	};

	return (
		<AdminLayout title={heading}>
			<Heading>{heading}</Heading>

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

			{purchases.data && purchases.data.length > 0 && (
				<SearchResultsWithFilters
					paginatedData={purchases}
					filterBadges={filterBadges}
				/>
			)}

			{children}

			<VendorPaymentForm
				purchases={purchases}
				selectedPurchasesIds={selectedPurchasesIds}
				setSelectedPurchasesIds={handleSelectedPurchasesIdsChange}
				showDialog={showDialog}
				setShowDialog={setShowDialog}
				selectedPurchasesDetails={selectedPurchasesDetails}
				vendorPayment={vendorPayment}
			/>

			<VendorPaymentBanner
				selectedPurchasesDetails={selectedPurchasesDetails}
				onContinue={handleBannerContinue}
			/>
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
