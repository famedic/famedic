import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { ArrowPathIcon, FunnelIcon } from "@heroicons/react/16/solid";
import DateFilter from "@/Components/Filters/DateFilter";
import { ListboxLabel, ListboxOption } from "@/Components/Catalyst/listbox";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import ListboxFilter from "@/Components/Filters/ListboxFilter";

export default function FunnelsToolbar({ filters, funnelOptions = [], meta }) {
	const { data, setData, get, processing } = useForm({
		funnel: filters?.funnel || "general",
		start_date: filters?.start_date || "",
		end_date: filters?.end_date || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.funnels"), {
			preserveState: true,
			replace: true,
		});
	};

	const refresh = () => {
		router.get(
			route("admin.activecampaign.funnels"),
			{
				funnel: filters?.funnel || "general",
				start_date: filters?.start_date || "",
				end_date: filters?.end_date || "",
				refresh: 1,
			},
			{ preserveState: false },
		);
	};

	return (
		<ChartCard
			title="Periodo y funnel"
			description={
				meta?.previous_period
					? `Comparado contra ${meta.previous_period.start_date} — ${meta.previous_period.end_date}.`
					: "Selecciona embudo y rango de fechas."
			}
		>
			<form
				onSubmit={apply}
				className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5"
			>
				<ListboxFilter
					label="Funnel"
					value={data.funnel}
					onChange={(value) => setData("funnel", value)}
				>
					{funnelOptions.map((opt) => (
						<ListboxOption key={opt.value} value={opt.value}>
							<FunnelIcon />
							<ListboxLabel>{opt.label}</ListboxLabel>
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

				<div className="flex items-end gap-2 sm:col-span-2 lg:col-span-2">
					<Button type="submit" disabled={processing} className="w-full sm:w-auto">
						Aplicar
					</Button>
					<Button type="button" outline disabled={processing} onClick={refresh}>
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
