import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme.jsx";
import SearchInput from "@/Components/Admin/SearchInput";
import DateFilter from "@/Components/Filters/DateFilter";
import {
	Squares2X2Icon,
	TableCellsIcon,
} from "@heroicons/react/16/solid";

const SELECT_CLASS =
	"w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm outline-none focus:border-zinc-400 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100";

export default function ReferralFilters({
	filters = {},
	filterOptions = {},
	open = true,
}) {
	const form = useForm({
		search: filters.search || "",
		start_date: filters.start_date || "",
		end_date: filters.end_date || "",
		status: filters.status || "",
		company: filters.company || "",
		source: filters.source || "",
		city: filters.city || "",
		segment: filters.segment || "",
		type: filters.type || "",
		granularity: filters.granularity || "day",
		tab: filters.tab || "overview",
		view: filters.view || "table",
		compare_mode: filters.compare_mode || "period",
	});

	const apply = (e) => {
		e?.preventDefault?.();
		form.get(route("admin.customers.referrals"), {
			preserveState: true,
			replace: true,
		});
	};

	const setView = (view) => {
		router.get(
			route("admin.customers.referrals"),
			{ ...filters, view, tab: "inviters" },
			{ preserveState: true, replace: true },
		);
	};

	if (!open) {
		return null;
	}

	return (
		<ChartCard
			title="Filtros"
			description="Busca invitadores y acota el periodo de registros referidos."
		>
			<form onSubmit={apply} className="space-y-4">
				<div className="flex flex-col gap-3 lg:flex-row lg:items-center">
					<div className="flex-1">
						<SearchInput
							value={form.data.search}
							onChange={(value) => form.setData("search", value)}
							placeholder="Buscar invitador por nombre, correo o teléfono..."
						/>
					</div>
					<div className="flex gap-2">
						<button
							type="button"
							onClick={() => setView("table")}
							className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold transition ${
								(filters.view || "table") === "table"
									? "border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900"
									: "border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300"
							}`}
						>
							<TableCellsIcon className="size-4" />
							Vista Tabla
						</button>
						<button
							type="button"
							onClick={() => setView("cards")}
							className={`inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 text-xs font-semibold transition ${
								filters.view === "cards"
									? "border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900"
									: "border-zinc-200 text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300"
							}`}
						>
							<Squares2X2Icon className="size-4" />
							Vista Tarjetas
						</button>
					</div>
				</div>

				<div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
					<DateFilter
						label="Desde"
						value={form.data.start_date}
						onChange={(value) => form.setData("start_date", value)}
					/>
					<DateFilter
						label="Hasta"
						value={form.data.end_date}
						onChange={(value) => form.setData("end_date", value)}
					/>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Estado referido
						<select
							className={SELECT_CLASS}
							value={form.data.status}
							onChange={(e) => form.setData("status", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="nuevo">Nuevo</option>
							<option value="verificado">Verificado</option>
							<option value="compro">Compró</option>
							<option value="membresia">Membresía</option>
							<option value="inactivo">Inactivo</option>
						</select>
					</label>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Empresa
						<select
							className={SELECT_CLASS}
							value={form.data.company}
							onChange={(e) => form.setData("company", e.target.value)}
						>
							<option value="">Todas</option>
							{(filterOptions.companies || []).map((company) => (
								<option key={company} value={company}>
									{company}
								</option>
							))}
						</select>
					</label>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Fuente
						<select
							className={SELECT_CLASS}
							value={form.data.source}
							onChange={(e) => form.setData("source", e.target.value)}
						>
							<option value="">Todas</option>
							<option value="odessa">Odessa</option>
							<option value="familiar">Familiar</option>
							<option value="regular">Regular</option>
						</select>
					</label>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Ciudad
						<select
							className={SELECT_CLASS}
							value={form.data.city}
							onChange={(e) => form.setData("city", e.target.value)}
						>
							<option value="">Todas</option>
							{(filterOptions.cities || []).map((city) => (
								<option key={city} value={city}>
									{city}
								</option>
							))}
						</select>
					</label>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Segmento / Nivel
						<select
							className={SELECT_CLASS}
							value={form.data.segment}
							onChange={(e) => form.setData("segment", e.target.value)}
						>
							<option value="">Todos</option>
							<option value="bronce">Bronce</option>
							<option value="plata">Plata</option>
							<option value="oro">Oro</option>
							<option value="platino">Platino</option>
							<option value="diamante">Diamante</option>
						</select>
					</label>
					<label className="space-y-1 text-xs font-medium text-zinc-500">
						Comparador
						<select
							className={SELECT_CLASS}
							value={form.data.compare_mode}
							onChange={(e) => form.setData("compare_mode", e.target.value)}
						>
							<option value="period">Periodo vs anterior</option>
							<option value="month_vs_previous">Este mes vs pasado</option>
							<option value="30_vs_90">Últimos 30 vs 90</option>
						</select>
					</label>
				</div>

				<div className="flex justify-end">
					<Button type="submit" disabled={form.processing}>
						Aplicar filtros
					</Button>
				</div>
			</form>
		</ChartCard>
	);
}
