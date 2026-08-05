import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function HealthFilters({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		search: filters.search || "",
		type: filters.type || "",
		source: filters.source || "",
		state: filters.state || "",
		city: filters.city || "",
		health_band: filters.health_band || "",
		segment: filters.segment || "",
		sort: filters.sort || "health_desc",
		tab: filters.tab || "overview",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.customer-intelligence.customer-health"), {
			preserveState: true,
		});
	};

	return (
		<ChartCard
			title="Filtros"
			description="Segmenta por banda de salud, persona predictiva y origen."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
			>
				<div className="sm:col-span-2">
					<label className="mb-1.5 block text-xs font-medium text-zinc-600 dark:text-zinc-400">
						Buscar
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
					label="Health band"
					placeholder="Todas"
					value={data.health_band}
					onChange={(value) => setData("health_band", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{(filterOptions.health_bands || []).map((band) => (
						<ListboxOption key={band.value} value={band.value}>
							<ListboxLabel>{band.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Segmento / persona"
					placeholder="Todos"
					value={data.segment}
					onChange={(value) => setData("segment", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.segments || []).map((segment) => (
						<ListboxOption key={segment.value} value={segment.value}>
							<ListboxLabel>{segment.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Fuente"
					placeholder="Todas"
					value={data.source}
					onChange={(value) => setData("source", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{(filterOptions.sources || []).map((source) => (
						<ListboxOption key={source.value} value={source.value}>
							<ListboxLabel>{source.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Orden"
					placeholder="Health ↓"
					value={data.sort}
					onChange={(value) => setData("sort", value)}
				>
					{(filterOptions.sorts || []).map((sort) => (
						<ListboxOption key={sort.value} value={sort.value}>
							<ListboxLabel>{sort.label}</ListboxLabel>
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
							router.get(route("admin.customer-intelligence.customer-health"))
						}
					>
						Limpiar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
