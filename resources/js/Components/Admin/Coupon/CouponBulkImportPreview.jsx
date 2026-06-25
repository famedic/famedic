import { useMemo, useState } from "react";
import CouponBulkImportActionBar from "@/Components/Admin/Coupon/CouponBulkImportActionBar";
import CouponBulkImportContinueSummary from "@/Components/Admin/Coupon/CouponBulkImportContinueSummary";
import CouponBulkImportFilters from "@/Components/Admin/Coupon/CouponBulkImportFilters";
import CouponBulkImportPreviewTable from "@/Components/Admin/Coupon/CouponBulkImportPreviewTable";
import CouponBulkImportSummary from "@/Components/Admin/Coupon/CouponBulkImportSummary";
import CouponSectionCard from "@/Components/Admin/Coupon/CouponSectionCard";
import { Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import {
	BULK_IMPORT_LARGE_FILE_THRESHOLD,
	BULK_IMPORT_PAGE_SIZE,
	computeBulkImportSummary,
	computeContinueSummaryFromRows,
	filterBulkImportRows,
	isBulkRowConfirmable,
	isBulkRowDuplicate,
	isBulkRowInvalid,
	paginateBulkImportRows,
	resolveBulkRowStatus,
} from "@/lib/couponBulkImportPreview";
import { isConfirmableBeneficiaryStatus } from "@/lib/couponBeneficiaryAssign";

function withIndex(rows) {
	return rows.map((row, index) => ({ ...row, _index: index }));
}

export default function CouponBulkImportPreview({ rows, onChangeRows, apiSummary = null }) {
	const [filter, setFilter] = useState("all");
	const [search, setSearch] = useState("");
	const [page, setPage] = useState(1);
	const [selectionRevision, setSelectionRevision] = useState(0);

	const indexedRows = useMemo(() => withIndex(rows), [rows]);

	const summary = useMemo(() => computeBulkImportSummary(indexedRows), [indexedRows]);

	const filterCounts = useMemo(() => {
		const counts = { all: indexedRows.length, selected: 0, unselected: 0 };
		for (const row of indexedRows) {
			const status = resolveBulkRowStatus(row);
			if (row.include) counts.selected += 1;
			else counts.unselected += 1;
			if (status === "valid_registered_user") counts.registered = (counts.registered ?? 0) + 1;
			if (status === "valid_pending_user") counts.pending = (counts.pending ?? 0) + 1;
			if (status === "invalid_email") {
				counts.invalid = (counts.invalid ?? 0) + 1;
			}
			if (isBulkRowDuplicate(row)) counts.duplicate = (counts.duplicate ?? 0) + 1;
		}
		return counts;
	}, [indexedRows]);

	const filteredRows = useMemo(
		() => filterBulkImportRows(indexedRows, { filter, search }),
		[indexedRows, filter, search],
	);

	const pageInfo = useMemo(() => {
		const p = paginateBulkImportRows(filteredRows, page, BULK_IMPORT_PAGE_SIZE);
		return {
			...p,
			onPageChange: setPage,
		};
	}, [filteredRows, page]);

	const continueSummary = useMemo(
		() => computeContinueSummaryFromRows(indexedRows),
		[indexedRows],
	);

	const isLargeFile = summary.total > BULK_IMPORT_LARGE_FILE_THRESHOLD;

	const resetListView = () => {
		setFilter("all");
		setSearch("");
		setPage(1);
	};

	const updateRows = (updater, { resetListView: shouldResetListView = false } = {}) => {
		onChangeRows(updater);
		if (shouldResetListView) {
			resetListView();
			setSelectionRevision((n) => n + 1);
		} else {
			setPage(1);
		}
	};

	const handleAction = (actionId) => {
		switch (actionId) {
			case "selectAll":
			case "includePending":
				updateRows(
					(current) =>
						current.map((row) => ({
							...row,
							include: isConfirmableBeneficiaryStatus(resolveBulkRowStatus(row)),
						})),
					{ resetListView: true },
				);
				break;
			case "selectRegistered":
				updateRows(
					(current) =>
						current.map((row) => ({
							...row,
							include: resolveBulkRowStatus(row) === "valid_registered_user",
						})),
					{ resetListView: true },
				);
				break;
			case "deselectAll":
				updateRows(
					(current) =>
						current.map((row) => ({
							...row,
							include: false,
						})),
					{ resetListView: true },
				);
				break;
			case "deselectInvalid":
				updateRows(
					(current) =>
						current.map((row) =>
							isBulkRowInvalid(row) ? { ...row, include: false } : row,
						),
					{ resetListView: true },
				);
				break;
			case "removeDuplicates":
				updateRows((current) => current.filter((row) => !isBulkRowDuplicate(row)), {
					resetListView: true,
				});
				break;
			case "removeInvalid":
				updateRows((current) => current.filter((row) => !isBulkRowInvalid(row)), {
					resetListView: true,
				});
				break;
			case "resetSelection":
				updateRows(
					(current) =>
						current.map((row) => ({
							...row,
							include: isConfirmableBeneficiaryStatus(resolveBulkRowStatus(row)),
						})),
					{ resetListView: true },
				);
				break;
			default:
				break;
		}
	};

	const handleFilterChange = (next) => {
		setFilter(next);
		setPage(1);
	};

	const handleSearchChange = (next) => {
		setSearch(next);
		setPage(1);
	};

	return (
		<CouponSectionCard
			title="Resultado del análisis"
			description={
				apiSummary?.slots_remaining != null
					? `Cupo disponible en campaña: ${apiSummary.slots_remaining}`
					: undefined
			}
		>
			<div className="space-y-5">
				<CouponBulkImportSummary summary={summary} isLargeFile={isLargeFile} />

				<div>
					<Subheading className="text-base">Revisión de registros</Subheading>
					<Text className="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
						Marca quién recibirá el crédito o quedará como beneficiario pendiente. Usa filtros
						para revisar excepciones sin recorrer todo el archivo.
					</Text>
				</div>

				<CouponBulkImportFilters
					filter={filter}
					onFilterChange={handleFilterChange}
					search={search}
					onSearchChange={handleSearchChange}
					counts={filterCounts}
				/>

				<CouponBulkImportActionBar onAction={handleAction} />

				<CouponBulkImportPreviewTable
					key={selectionRevision}
					rows={pageInfo.items}
					pageInfo={pageInfo}
					onToggleInclude={(index, include) =>
						updateRows((current) =>
							current.map((row, i) => (i === index ? { ...row, include } : row)),
						)
					}
					onRemoveRow={(index) =>
						updateRows((current) => current.filter((_, i) => i !== index))
					}
					onUpdateRow={(index, patch) =>
						updateRows((current) =>
							current.map((row, i) => (i === index ? { ...row, ...patch } : row)),
						)
					}
				/>

				<CouponBulkImportContinueSummary {...continueSummary} />
			</div>
		</CouponSectionCard>
	);
}
