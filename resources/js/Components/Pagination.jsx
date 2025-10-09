import {
	Pagination as CatalystPagination,
	PaginationGap,
	PaginationList,
	PaginationNext,
	PaginationPage,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";

export default function Pagination({ paginatedModels }) {
	if (paginatedModels.total <= 0) return;

	return (
		<CatalystPagination>
			<PaginationPrevious href={paginatedModels.prev_page_url} />
			{paginatedModels.links.length > 1 && (
				<PaginationList>
					{paginatedModels.links.map((link, index) =>
						link.label === "..." ? (
							<PaginationGap key={`gap-${index}`} />
						) : (
							link.label !== "&laquo; Anterior" &&
							link.label !== "Siguiente &raquo;" && (
								<PaginationPage
									current={link.active}
									key={link.label}
									href={link.url}
								>
									{link.label}
								</PaginationPage>
							)
						),
					)}
				</PaginationList>
			)}
			<PaginationNext href={paginatedModels.next_page_url} />
		</CatalystPagination>
	);
}
