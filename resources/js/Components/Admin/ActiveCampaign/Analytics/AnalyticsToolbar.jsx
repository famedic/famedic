import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon } from "@heroicons/react/16/solid";
import DateFilter from "@/Components/Filters/DateFilter";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";

export default function AnalyticsToolbar({ filters, meta, refreshing = false }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters?.start_date || "",
		end_date: filters?.end_date || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.analytics"), {
			preserveState: true,
			replace: true,
		});
	};

	const refresh = () => {
		router.get(
			route("admin.activecampaign.analytics"),
			{
				start_date: filters?.start_date || "",
				end_date: filters?.end_date || "",
				refresh: 1,
			},
			{ preserveState: false },
		);
	};


	return (
		<ChartCard
			title="Periodo de decisión"
			description={
				meta?.previous_period
					? `Comparado contra ${meta.previous_period.start_date} — ${meta.previous_period.end_date}. Fuente: mismas agregaciones del Dashboard.`
					: "Filtro de periodo (máx. 90 días)."
			}
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4"
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
				<div className="flex items-end gap-2 sm:col-span-2">
					<Button type="submit" disabled={processing} className="w-full sm:w-auto">
						Aplicar
					</Button>
					<Button
						type="button"
						outline
						disabled={processing || refreshing}
						onClick={refresh}
					>
						<ArrowPathIcon className="size-4" />
						Actualizar
					</Button>
				</div>
			</form>
			{meta?.generated_at ? (
				<p className="mt-3 text-[11px] text-zinc-400">
					Actualizado {meta.generated_at}
				</p>
			) : null}
		</ChartCard>
	);
}
