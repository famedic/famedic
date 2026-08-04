import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "./chartTheme.jsx";

export default function FiltersBar({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		brand: filters.brand || "",
		display_status: filters.display_status || "",
		payment_method: filters.payment_method || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.carts.dashboard"), { preserveState: true });
	};

	return (
		<ChartCard
			title="Filtros"
			description="El periodo anterior se calcula automáticamente para los deltas."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 md:grid-cols-3 xl:grid-cols-4"
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
					label="Marca / laboratorio"
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
					label="Estado"
					placeholder="Todos"
					value={data.display_status}
					onChange={(value) => setData("display_status", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="completed">
						<ListboxLabel>Comprado</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="abandoned">
						<ListboxLabel>Abandonado</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="active">
						<ListboxLabel>Activo</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>
				<ListboxFilter
					label="Método de pago"
					placeholder="Todos"
					value={data.payment_method}
					onChange={(value) => setData("payment_method", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{(filterOptions.payment_methods || []).map((method) => (
						<ListboxOption key={method.value} value={method.value}>
							<ListboxLabel>{method.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<div className="flex items-end md:col-span-2 xl:col-span-1">
					<Button type="submit" disabled={processing} className="w-full">
						Aplicar filtros
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
