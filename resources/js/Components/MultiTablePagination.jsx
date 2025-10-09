import {
	Pagination,
	PaginationGap,
	PaginationList,
} from "@/Components/Catalyst/pagination";
import { Button } from "@/Components/Catalyst/button";
import { ArrowLeftIcon, ArrowRightIcon } from "@heroicons/react/16/solid";
import { router } from "@inertiajs/react";

export default function MultiTablePagination({ paginatedModels, only = [] }) {
	if (paginatedModels.total <= 0) return;

	const handlePaginationClick = (url) => {
		if (!url) return;

		router.visit(url, {
			preserveState: true,
			preserveScroll: true,
			only: only.length > 0 ? only : undefined,
		});
	};

	return (
		<Pagination className="mt-4">
			<PaginationPrevious
				onClick={() =>
					handlePaginationClick(paginatedModels.prev_page_url)
				}
				disabled={!paginatedModels.prev_page_url}
			/>
			{paginatedModels.links.length > 1 && (
				<PaginationList>
					{paginatedModels.links.map((link, index) =>
						link.label === "..." ? (
							<PaginationGap key={`gap-${index}`} />
						) : (
							link.label !== "&laquo; Anterior" &&
							link.label !== "Siguiente &raquo;" && (
								<PaginationPageButton
									key={link.label}
									current={link.active}
									onClick={() =>
										handlePaginationClick(link.url)
									}
									disabled={!link.url}
								>
									{link.label}
								</PaginationPageButton>
							)
						),
					)}
				</PaginationList>
			)}
			<PaginationNext
				onClick={() =>
					handlePaginationClick(paginatedModels.next_page_url)
				}
				disabled={!paginatedModels.next_page_url}
			/>
		</Pagination>
	);
}

function PaginationPrevious({ onClick, disabled, children = "Anterior" }) {
	return (
		<span className="grow basis-0">
			<Button
				onClick={onClick}
				disabled={disabled}
				plain={disabled}
				outline={!disabled}
				aria-label="Previous page"
			>
				<ArrowLeftIcon />
				{children}
			</Button>
		</span>
	);
}

function PaginationNext({ onClick, disabled, children = "Siguiente" }) {
	return (
		<span className="flex grow basis-0 justify-end">
			<Button
				onClick={onClick}
				disabled={disabled}
				plain={disabled}
				outline={!disabled}
				color="famedic"
				aria-label="Next page"
			>
				{children}
				<ArrowRightIcon />
			</Button>
		</span>
	);
}

function PaginationPageButton({ current, onClick, disabled, children }) {
	return (
		<Button
			onClick={onClick}
			disabled={disabled}
			plain={!current}
			outline={current}
			aria-label={`Page ${children}`}
			aria-current={current ? "page" : undefined}
			className="min-w-[2.25rem] before:absolute before:-inset-px before:rounded-lg"
		>
			<span className="-mx-0.5">{children}</span>
		</Button>
	);
}
