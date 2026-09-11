import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "./chartTheme.jsx";

export default function FiltersBar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		period: filters.period || "last_30_days",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		brand: filters.brand || "",
		type: filters.type || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.carts.dashboard"), { preserveState: true });
	};

	const isCustom = data.period === "custom";

	return (
		<ChartCard
			title="Filtros"
			description="Las fechas se agrupan desde backend con la zona horaria del negocio."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 md:grid-cols-3 xl:grid-cols-6"
			>
				<ListboxFilter
					label="Periodo"
					placeholder="Ultimos 30 dias"
					value={data.period}
					onChange={(value) => setData("period", value)}
				>
					{(filterOptions.periods || []).map((period) => (
						<ListboxOption key={period.value} value={period.value}>
							<ListboxLabel>{period.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<DateFilter
					label="Desde"
					value={data.start_date}
					onChange={(value) => {
						setData("period", "custom");
						setData("start_date", value);
					}}
					disabled={!isCustom}
				/>
				<DateFilter
					label="Hasta"
					value={data.end_date}
					onChange={(value) => {
						setData("period", "custom");
						setData("end_date", value);
					}}
					disabled={!isCustom}
				/>
				<ListboxFilter
					label="Marca"
					placeholder="Todas"
					value={data.brand}
					onChange={(value) => setData("brand", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{(filterOptions.brands || []).map((brand) => (
						<ListboxOption key={brand.value} value={brand.value}>
							<ListboxLabel>{brand.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<ListboxFilter
					label="Tipo"
					placeholder="Todos"
					value={data.type}
					onChange={(value) => setData("type", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.types || []).map((type) => (
						<ListboxOption key={type.value} value={type.value}>
							<ListboxLabel>{type.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<div className="flex items-end">
					<Button type="submit" disabled={processing} className="w-full">
						Aplicar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
