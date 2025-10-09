import SearchResultsWithFilters from "@/Components/Admin/SearchResultsWithFilters";
import ExportDialog from "@/Components/ExportDialog";

export default function ResultsAndExport({
	paginatedData,
	filterBadges,
	canExport,
	filters,
	exportUrl,
	exportTitle,
}) {
	return (
		<>
			<SearchResultsWithFilters
				paginatedData={paginatedData}
				filterBadges={filterBadges}
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