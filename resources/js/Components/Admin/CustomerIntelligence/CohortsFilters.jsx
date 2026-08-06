import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function CohortsFilters({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		type: filters.type || "",
		source: filters.source || "",
		state: filters.state || "",
		city: filters.city || "",
		gender: filters.gender || "",
		max_weeks: filters.max_weeks || 12,
		max_cohorts: filters.max_cohorts || 6,
		tab: filters.tab || "overview",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.customer-intelligence.cohorts"), { preserveState: true });
	};

	return (
		<ChartCard
			title="Filtros y segmentación"
			description="Cohortes por mes de registro. Segmenta por fuente, geo y demografía."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
			>
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
					label="Tipo de usuario"
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
					label="Estado"
					placeholder="Todos"
					value={data.state}
					onChange={(value) => setData("state", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.states || []).map((state) => (
						<ListboxOption key={state.value} value={state.value}>
							<ListboxLabel>{state.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Ciudad"
					placeholder="Todas"
					value={data.city}
					onChange={(value) => setData("city", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{(filterOptions.cities || []).map((city) => (
						<ListboxOption key={city.value} value={city.value}>
							<ListboxLabel>{city.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Sexo"
					placeholder="Todos"
					value={data.gender}
					onChange={(value) => setData("gender", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.genders || []).map((gender) => (
						<ListboxOption key={gender.value} value={gender.value}>
							<ListboxLabel>{gender.label}</ListboxLabel>
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
						onClick={() => router.get(route("admin.customer-intelligence.cohorts"))}
					>
						Limpiar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
