import clsx from "clsx";
import { FunnelIcon } from "@heroicons/react/24/outline";
import SearchInput from "@/Components/Admin/SearchInput";
import FilterCountBadge from "@/Components/Admin/FilterCountBadge";
import { Button } from "@/Components/Catalyst/button";
import { Subheading } from "@/Components/Catalyst/heading";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";

const PIPELINE_CHIPS = [
	{ value: "all", label: "Todos" },
	{ value: "processing", label: "En proceso" },
	{ value: "completed", label: "Completados" },
	{ value: "invoiced", label: "Facturados" },
];

export default function OrdersFilters({
	data,
	setData,
	showFilters,
	setShowFilters,
	activeFiltersCount,
	filterOptions,
	onPipelineChange,
}) {
	return (
		<div className="space-y-4">
			<div className="flex flex-col justify-between gap-3 sm:flex-row sm:items-center sm:gap-4">
				<SearchInput
					value={data.search}
					onChange={(value) => setData("search", value)}
					placeholder="Buscar por estudio, folio o paciente"
				/>
				<Button
					type="button"
					outline
					className="min-h-11 w-full shrink-0 sm:w-auto sm:min-w-[8rem]"
					onClick={() => setShowFilters((previous) => !previous)}
				>
					{activeFiltersCount ? <FilterCountBadge count={activeFiltersCount} /> : <FunnelIcon className="size-5" />}
					Filtros
				</Button>
			</div>

			<div className="flex flex-wrap gap-2" role="tablist" aria-label="Embudo de pedidos">
				{PIPELINE_CHIPS.map((chip) => (
					<button
						key={chip.value}
						type="button"
						role="tab"
						aria-selected={data.pipeline === chip.value}
						onClick={() => onPipelineChange?.(chip.value)}
						className={clsx(
							"min-h-10 rounded-full px-4 text-sm font-medium transition duration-150",
							data.pipeline === chip.value
								? "bg-zinc-900 text-white shadow-sm dark:bg-white dark:text-slate-900"
								: "border border-zinc-200 bg-zinc-50/80 text-zinc-700 hover:bg-zinc-100 dark:border-slate-600 dark:bg-slate-800/80 dark:text-slate-200 dark:hover:bg-slate-800",
						)}
					>
						{chip.label}
					</button>
				))}
			</div>

			{showFilters && (
				<div className="space-y-4 rounded-2xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-slate-700 dark:bg-slate-900/50 sm:p-6">
					<Subheading className="text-base sm:text-lg">Filtros avanzados</Subheading>
					<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
						<ListboxFilter
							label="Paciente"
							placeholder="Paciente"
							value={data.patient}
							onChange={(value) => setData("patient", value)}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos los pacientes</ListboxLabel>
							</ListboxOption>
							{(filterOptions.patients || []).map((opt) => (
								<ListboxOption key={opt.value} value={opt.value}>
									<ListboxLabel>{opt.label}</ListboxLabel>
								</ListboxOption>
							))}
						</ListboxFilter>

						<ListboxFilter
							label="Estado del estudio"
							value={data.study_status}
							onChange={(value) => setData("study_status", value)}
						>
							{(filterOptions.study_statuses || []).map((opt) => (
								<ListboxOption key={opt.value} value={opt.value}>
									<ListboxLabel>{opt.label}</ListboxLabel>
								</ListboxOption>
							))}
						</ListboxFilter>

						<ListboxFilter
							label="Forma de pago"
							value={data.payment_method}
							onChange={(value) => setData("payment_method", value)}
						>
							{(filterOptions.payment_methods || []).map((opt) => (
								<ListboxOption
									key={opt.value === "" ? "any-payment-method" : opt.value}
									value={opt.value}
								>
									<ListboxLabel>{opt.label}</ListboxLabel>
								</ListboxOption>
							))}
						</ListboxFilter>

						<ListboxFilter
							label="Laboratorio"
							value={data.brand}
							onChange={(value) => setData("brand", value)}
						>
							<ListboxOption value="">
								<ListboxLabel>Todos</ListboxLabel>
							</ListboxOption>
							{(filterOptions.laboratory_brands || []).map((opt) => (
								<ListboxOption key={opt.value} value={opt.value}>
									<ListboxLabel>{opt.label}</ListboxLabel>
								</ListboxOption>
							))}
						</ListboxFilter>

						<DateFilter
							label="Desde"
							value={data.start_date}
							onChange={(value) => setData("start_date", value)}
						/>
						<DateFilter
							label="Hasta"
							value={data.end_date}
							onChange={(value) => setData("end_date", value)}
						/>
					</div>
				</div>
			)}
		</div>
	);
}
