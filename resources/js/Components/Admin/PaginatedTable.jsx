import {
	Pagination,
	PaginationGap,
	PaginationList,
	PaginationNext,
	PaginationPage,
	PaginationPrevious,
} from "@/Components/Catalyst/pagination";

export default function PaginatedTable({ children, paginatedData }) {

	return (
		<>
			{children}
			<Pagination className="mt-4">
				<PaginationPrevious href={paginatedData.prev_page_url} />
				{paginatedData.links.length > 1 && (
					<PaginationList>
						{paginatedData.links.map((link, index) =>
							link.label === "..." ? (
								<PaginationGap key={`gap-${index}`} />
							) : (
								link.label !== "&laquo; Anterior" &&
								link.label !== "Siguiente &raquo;" && (
									<PaginationPage
										current={link.active}
										key={link.label}
										href={link.url || null}
									>
										{link.label}
									</PaginationPage>
								)
							),
						)}
					</PaginationList>
				)}
				<PaginationNext href={paginatedData.next_page_url} />
			</Pagination>
		</>
	);
}