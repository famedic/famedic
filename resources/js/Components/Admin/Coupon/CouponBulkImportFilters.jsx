import { Input } from "@/Components/Catalyst/input";
import { BULK_IMPORT_FILTER_OPTIONS } from "@/lib/couponBulkImportPreview";

function filterChipClass(active) {
	return [
		"inline-flex shrink-0 items-center rounded-full px-3 py-1.5 text-xs font-semibold transition-colors",
		active
			? "bg-famedic-lime/20 text-famedic-dark ring-1 ring-famedic-lime/50 dark:bg-famedic-lime/10 dark:text-famedic-lime"
			: "bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700",
	].join(" ");
}

export default function CouponBulkImportFilters({
	filter,
	onFilterChange,
	search,
	onSearchChange,
	counts = {},
}) {
	return (
		<div className="space-y-3">
			<div className="flex flex-wrap gap-2">
				{BULK_IMPORT_FILTER_OPTIONS.map((option) => {
					const count = counts[option.id];
					return (
						<button
							key={option.id}
							type="button"
							className={filterChipClass(filter === option.id)}
							onClick={() => onFilterChange(option.id)}
						>
							{option.label}
							{typeof count === "number" ? ` (${count})` : ""}
						</button>
					);
				})}
			</div>
			<Input
				type="search"
				value={search}
				placeholder="Buscar por correo o nombre…"
				onChange={(e) => onSearchChange(e.target.value)}
			/>
		</div>
	);
}
