import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Input } from "@/Components/Catalyst/input";
import { ListboxOption, ListboxLabel } from "@/Components/Catalyst/listbox";
import ListboxFilter from "@/Components/Filters/ListboxFilter";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";

export default function DormantFilters({ filters, filterOptions }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		search: filters.search || "",
		city: filters.city || "",
		state: filters.state || "",
		registration_source: filters.registration_source || "",
		email_verification: filters.email_verification || "",
		phone_verification: filters.phone_verification || "",
		referral_status: filters.referral_status || "",
		type: filters.type || "",
		days_bucket: filters.days_bucket || "",
		granularity: filters.granularity || "day",
		tab: filters.tab || "resumen",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.customers.dormant"), { preserveState: true });
	};

	const clear = () => {
		router.get(route("admin.customers.dormant"), {
			tab: data.tab || "resumen",
		});
	};

	return (
		<ChartCard
			title="Filtros"
			description="Segmenta la base dormida por registro, geografía, verificación y fuente."
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
						placeholder="Nombre, email o teléfono"
					/>
				</div>
				<DateFilter
					label="Registro desde"
					value={data.start_date}
					onChange={(value) => setData("start_date", value)}
				/>
				<DateFilter
					label="Registro hasta"
					value={data.end_date}
					onChange={(value) => setData("end_date", value)}
				/>
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
					label="Fuente de registro"
					placeholder="Todas"
					value={data.registration_source}
					onChange={(value) => setData("registration_source", value)}
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
					label="Verificación email"
					placeholder="Todas"
					value={data.email_verification}
					onChange={(value) => setData("email_verification", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="verified">
						<ListboxLabel>Verificado</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="unverified">
						<ListboxLabel>Sin verificar</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>
				<ListboxFilter
					label="Verificación teléfono"
					placeholder="Todas"
					value={data.phone_verification}
					onChange={(value) => setData("phone_verification", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="verified">
						<ListboxLabel>Verificado</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="unverified">
						<ListboxLabel>Sin verificar</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>
				<ListboxFilter
					label="Referido"
					placeholder="Todos"
					value={data.referral_status}
					onChange={(value) => setData("referral_status", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="referred">
						<ListboxLabel>Referidos</ListboxLabel>
					</ListboxOption>
					<ListboxOption value="not_referred">
						<ListboxLabel>No referidos</ListboxLabel>
					</ListboxOption>
				</ListboxFilter>
				<ListboxFilter
					label="Antigüedad"
					placeholder="Todas"
					value={data.days_bucket}
					onChange={(value) => setData("days_bucket", value)}
				>
					<ListboxOption value="">
						<ListboxLabel>Todas</ListboxLabel>
					</ListboxOption>
					{(filterOptions.days_buckets || []).map((bucket) => (
						<ListboxOption key={bucket.value} value={bucket.value}>
							<ListboxLabel>{bucket.label}</ListboxLabel>
						</ListboxOption>
					))}
				</ListboxFilter>
				<div className="flex items-end gap-2 sm:col-span-2 xl:col-span-1">
					<Button type="submit" disabled={processing} className="flex-1">
						Aplicar
					</Button>
					<Button type="button" outline onClick={clear} disabled={processing}>
						Limpiar
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
