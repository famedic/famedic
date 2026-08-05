import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function JourneyFilters({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		search: filters.search || "",
		type: filters.type || "",
		compare_mode: filters.compare_mode || "period",
		heatmap_metric: filters.heatmap_metric || "purchases",
		tab: filters.tab || "overview",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.customer-intelligence.customer-journey"), {
			preserveState: true,
		});
	};

	return (
		<ChartCard
			title="Filtros y comparador"
			description="Compara funnels entre periodos y segmenta el cohort."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
			>
				<div className="sm:col-span-2">
					<label className="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
						Buscar usuario
					</label>
					<Input
						value={data.search}
						onChange={(e) => setData("search", e.target.value)}
						placeholder="Nombre o email"
					/>
				</div>
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
				<ListboxFilter
					label="Comparador"
					placeholder="Periodo"
					value={data.compare_mode}
					onChange={(value) => setData("compare_mode", value)}
				>
					{(filterOptions.compare_modes || []).map((mode) => (
						<ListboxOption key={mode.value} value={mode.value}>
							<ListboxLabel>{mode.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Tipo de cuenta"
					placeholder="Todos"
					value={data.type}
					onChange={(value) => setData("type", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.account_types || []).map((type) => (
						<ListboxOption key={type.value} value={type.value}>
							<ListboxLabel>{type.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Heatmap"
					placeholder="Compras"
					value={data.heatmap_metric}
					onChange={(value) => setData("heatmap_metric", value)}
				>
					{(filterOptions.heatmap_metrics || []).map((metric) => (
						<ListboxOption key={metric.value} value={metric.value}>
							<ListboxLabel>{metric.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<div className="flex items-end gap-2">
					<Button type="submit" disabled={processing} className="flex-1">
						Aplicar
					</Button>
					<Button
						type="button"
						outline
						onClick={() =>
							router.get(route("admin.customer-intelligence.customer-journey"))
						}
					>
						Limpiar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
