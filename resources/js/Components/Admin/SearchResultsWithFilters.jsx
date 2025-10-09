import SearchResultsMessage from "@/Components/SearchResultsMessage";
import AppliedFilters from "@/Components/AppliedFilters";

export default function SearchResultsWithFilters({
	paginatedData,
	filterBadges,
}) {
	return (
		<div className="mb-4 space-y-1">
			<SearchResultsMessage paginatedData={paginatedData} />
			<AppliedFilters filterBadges={filterBadges} />
		</div>
	);
}
