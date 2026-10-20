import { useForm } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Text } from "@/Components/Catalyst/text";

function SelectFilter({ label, value, onChange, options = [], empty = "Todos" }) {
	return (
		<label className="block space-y-1">
			<Text className="text-xs text-zinc-500">{label}</Text>
			<select
				value={value || ""}
				onChange={(event) => onChange(event.target.value)}
				className="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
			>
				<option value="">{empty}</option>
				{options.map((option) => (
					<option key={option.value} value={option.value}>
						{option.label}
					</option>
				))}
			</select>
		</label>
	);
}

export default function ResultsCenterFilters({ filters = {}, filterOptions = {} }) {
	const { data, setData, get, processing } = useForm({
		purchase_id: filters.purchase_id || "",
		folio: filters.folio || "",
		status: filters.status || "",
		extraction_status: filters.extraction_status || "",
		structured_status: filters.structured_status || "",
		extraction_method: filters.extraction_method || "",
		has_errors: filters.has_errors || "",
		ai_explanation_status: filters.ai_explanation_status || "",
		date_from: filters.date_from || "",
		date_to: filters.date_to || "",
	});

	const submit = (event) => {
		event.preventDefault();
		get(route("admin.laboratory-results-center.index"), {
			preserveState: true,
			preserveScroll: true,
		});
	};

	const clear = () => {
		window.location.href = route("admin.laboratory-results-center.index");
	};

	return (
		<form onSubmit={submit} className="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
			<div className="grid gap-3 md:grid-cols-3 xl:grid-cols-5">
				<label className="block space-y-1">
					<Text className="text-xs text-zinc-500">Purchase ID</Text>
					<input
						value={data.purchase_id}
						onChange={(event) => setData("purchase_id", event.target.value)}
						className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
					/>
				</label>
				<label className="block space-y-1">
					<Text className="text-xs text-zinc-500">Folio</Text>
					<input
						value={data.folio}
						onChange={(event) => setData("folio", event.target.value)}
						className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
					/>
				</label>
				<SelectFilter label="Estado" value={data.status} onChange={(value) => setData("status", value)} options={filterOptions.statuses} />
				<SelectFilter label="Extracción" value={data.extraction_status} onChange={(value) => setData("extraction_status", value)} options={filterOptions.extraction_statuses} />
				<SelectFilter label="Structured" value={data.structured_status} onChange={(value) => setData("structured_status", value)} options={filterOptions.structured_statuses} />
				<SelectFilter label="Método" value={data.extraction_method} onChange={(value) => setData("extraction_method", value)} options={filterOptions.extraction_methods} />
				<SelectFilter
					label="Errores"
					value={data.has_errors}
					onChange={(value) => setData("has_errors", value)}
					options={[
						{ value: "true", label: "Con errores" },
						{ value: "false", label: "Sin errores" },
					]}
				/>
				<SelectFilter label="AI Explanation" value={data.ai_explanation_status} onChange={(value) => setData("ai_explanation_status", value)} options={filterOptions.ai_explanation_statuses} />
				<label className="block space-y-1">
					<Text className="text-xs text-zinc-500">Desde</Text>
					<input
						type="date"
						value={data.date_from}
						onChange={(event) => setData("date_from", event.target.value)}
						className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
					/>
				</label>
				<label className="block space-y-1">
					<Text className="text-xs text-zinc-500">Hasta</Text>
					<input
						type="date"
						value={data.date_to}
						onChange={(event) => setData("date_to", event.target.value)}
						className="w-full rounded-md border border-zinc-300 px-2 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-900"
					/>
				</label>
			</div>
			<div className="mt-4 flex flex-wrap justify-end gap-2">
				<Button type="button" outline onClick={clear}>
					Limpiar
				</Button>
				<Button type="submit" color="famedic" disabled={processing}>
					Filtrar
				</Button>
			</div>
		</form>
	);
}
