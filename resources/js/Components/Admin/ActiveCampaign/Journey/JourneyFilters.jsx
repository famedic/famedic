import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import DateFilter from "@/Components/Filters/DateFilter";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import SearchInput from "@/Components/Admin/SearchInput";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import { ArchiveBoxIcon } from "@heroicons/react/16/solid";

export default function JourneyFilters({
	filters,
	contactOptions = [],
	typeOptions = [],
}) {
	const { data, setData, get, processing } = useForm({
		search: filters?.search || "",
		contact_id: filters?.contact_id ? String(filters.contact_id) : "",
		start_date: filters?.start_date || "",
		end_date: filters?.end_date || "",
		type: filters?.type || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.customer-journey"), {
			preserveState: true,
			replace: true,
		});
	};

	return (
		<ChartCard
			title="Filtros del Journey"
			description="Selecciona un paciente. El recorrido se construye desde el Timeline de Famedic."
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6"
			>
				<div className="sm:col-span-2">
					<SearchInput
						value={data.search}
						onChange={(value) => setData("search", value)}
						placeholder="Buscar paciente por nombre…"
					/>
				</div>

				<ListboxFilter
					label="Paciente"
					value={data.contact_id}
					onChange={(value) => setData("contact_id", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Seleccionar…</ListboxLabel>
					</ListboxOption>
					{contactOptions.map((option) => (
						<ListboxOption key={option.id} value={String(option.id)}>
							<ListboxLabel>{option.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>

				<ListboxFilter
					label="Tipo de evento"
					value={data.type}
					onChange={(value) => setData("type", value)}
				>
					<ListboxOption value="">
						<ArchiveBoxIcon />
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					{typeOptions.map((option) => (
						<ListboxOption key={option.value} value={option.value}>
							<ListboxLabel>{option.label}</ListboxLabel>
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

				<div className="flex items-end sm:col-span-2 xl:col-span-1">
					<Button type="submit" disabled={processing} className="w-full">
						Aplicar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
