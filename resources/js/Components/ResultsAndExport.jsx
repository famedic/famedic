import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import ExportDialog from "@/Components/ExportDialog";

export default function ResultsAndExport({
	paginatedData,
	filterBadges,
	canExport,
	filters,
	exportUrl,
	exportTitle,
	hideAppliedFilters = false,
}) {
	return (
		<>
			<SearchResultsWithFilters
				paginatedData={paginatedData}
				filterBadges={hideAppliedFilters ? [] : filterBadges}
			/>
			<ExportDialog
				canExport={canExport}
				filters={filters}
				filterBadges={filterBadges}
				exportUrl={exportUrl}
				title={exportTitle}
			/>
		</>
	);
}