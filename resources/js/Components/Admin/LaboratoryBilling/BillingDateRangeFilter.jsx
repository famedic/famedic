import { useEffect, useMemo, useState } from "react";
import { router } from "@inertiajs/react";
import DateFilter from "@/Components/Filters/DateFilter";
import { Button } from "@/Components/Catalyst/button";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import UpdateButton from "@/Components/Admin/UpdateButton";
import { FunnelIcon, ArrowDownTrayIcon } from "@heroicons/react/16/solid";
import { billingPanelClass } from "./billingUi";

function cleanParams(params) {
	return Object.fromEntries(
		Object.entries(params).filter(
			([, value]) => value !== null && value !== undefined && value !== "",
		),
	);
}

export default function BillingDateRangeFilter({
	filters = {},
	routeName,
	extraParams = {},
	showFiltersToggle = false,
	children = null,
	exportHref = null,
	onProcessingChange = null,
}) {
	const [from, setFrom] = useState(filters.from || "");
	const [to, setTo] = useState(filters.to || "");
	const [showFilters, setShowFilters] = useState(false);
	const [processing, setProcessing] = useState(false);

	useEffect(() => {
		setFrom(filters.from || "");
		setTo(filters.to || "");
	}, [filters.from, filters.to]);

	useEffect(() => {
		onProcessingChange?.(processing);
	}, [processing, onProcessingChange]);

	const dateDirty =
		(from || "") !== (filters.from || "") || (to || "") !== (filters.to || "");

	const extraDirty = useMemo(() => {
		return Object.entries(extraParams).some(([key, value]) => {
			const current = filters[key] ?? "";
			return String(value ?? "") !== String(current ?? "");
		});
	}, [extraParams, filters]);

	const showUpdate = dateDirty || extraDirty;

	const filtersCount = useMemo(() => {
		let count = 0;
		if (filters.document) count += 1;
		if (filters.brand) count += 1;
		if (filters.status && showFiltersToggle) count += 0; // status vive en tabs
		if (filters.usage) count += 1;
		if (filters.tipo_persona) count += 1;
		if (filters.search) count += 1;
		if (filters.include_deleted) count += 1;
		if (filters.created_in_range) count += 1;
		return count;
	}, [filters, showFiltersToggle]);

	const visit = (params) => {
		setProcessing(true);
		router.get(route(routeName, cleanParams(params)), {}, {
			preserveState: true,
			preserveScroll: true,
			onFinish: () => setProcessing(false),
		});
	};

	const submit = (e) => {
		e?.preventDefault?.();
		visit({
			...Object.fromEntries(
				Object.entries(filters).filter(
					([key]) =>
						!["from", "to", "formatted_from", "formatted_to"].includes(key),
				),
			),
			...extraParams,
			from,
			to,
		});
	};

	const clear = () => {
		setFrom("");
		setTo("");
		visit({});
	};

	return (
		<form onSubmit={submit} className="space-y-4">
			<div className="flex flex-col justify-between gap-4 md:flex-row md:items-end">
				<div className="grid w-full gap-3 sm:grid-cols-2 lg:max-w-xl">
					<DateFilter label="Desde" value={from} onChange={setFrom} />
					<DateFilter label="Hasta" value={to} onChange={setTo} />
				</div>
				<div className="flex flex-wrap items-center justify-end gap-2">
					{showFiltersToggle ? (
						<Button
							type="button"
							outline
							onClick={() => setShowFilters((value) => !value)}
							aria-expanded={showFilters}
						>
							{filtersCount > 0 ? (
								<FilterCountBadge count={filtersCount} />
							) : (
								<FunnelIcon data-slot="icon" />
							)}
							Filtros
						</Button>
					) : null}
					{exportHref ? (
						<Button href={exportHref} outline>
							<ArrowDownTrayIcon data-slot="icon" />
							Exportar CSV
						</Button>
					) : null}
					{showUpdate ? (
						<UpdateButton type="submit" processing={processing} />
					) : null}
					{(from || to) && !showUpdate ? (
						<Button type="button" outline onClick={clear} disabled={processing}>
							Limpiar fechas
						</Button>
					) : null}
				</div>
			</div>

			{showFiltersToggle ? (
				showFilters ? (
					<div className={billingPanelClass}>{children}</div>
				) : null
			) : children ? (
				<div className={billingPanelClass}>{children}</div>
			) : null}
		</form>
	);
}
