import { useForm, router } from "@inertiajs/react";
import { Button } from "@/Components/Catalyst/button";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { ChartCard } from "@/Components/Admin/CartsDashboard/chartTheme";
import DateFilter from "@/Components/Filters/DateFilter";

/**
 * Filtros de periodo + strip de fuentes (extensible: GA, Meta, WhatsApp…).
 */
export default function DashboardOverview({ filters, meta, disabled = false }) {
	const { data, setData, get, processing } = useForm({
		start_date: filters?.start_date || "",
		end_date: filters?.end_date || "",
	});

	const apply = (e) => {
		e.preventDefault();
		get(route("admin.activecampaign.dashboard"), { preserveState: true });
	};

	const reset = () => {
		const end = new Date();
		const start = new Date();
		start.setDate(end.getDate() - 6);
		const toInput = (date) => {
			const year = date.getFullYear();
			const month = String(date.getMonth() + 1).padStart(2, "0");
			const day = String(date.getDate()).padStart(2, "0");
			return `${year}-${month}-${day}`;
		};
		const next = {
			start_date: toInput(start),
			end_date: toInput(end),
		};
		setData(next);
		router.get(route("admin.activecampaign.dashboard"), next, {
			preserveState: true,
		});
	};

	const sources = meta?.sources || [];
	const notes = meta?.notes || [];

	return (
		<div className="space-y-4">
			<ChartCard
				title="Filtros"
				description="El periodo anterior se calcula automáticamente para los deltas (máx. 90 días)."
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
						<Button
							type="submit"
							disabled={processing || disabled}
							className="w-full sm:w-auto"
						>
							Aplicar filtros
						</Button>
						<Button
							type="button"
							outline
							disabled={processing || disabled}
							onClick={reset}
							className="w-full sm:w-auto"
						>
							Últimos 7 días
						</Button>
					</div>
				</form>
			</ChartCard>

			<div className="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 py-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
				<span className="text-[11px] font-semibold uppercase tracking-[0.14em] text-zinc-400">
					Fuentes
				</span>
				{sources.map((source) => (
					<Badge
						key={source.id}
						color={source.status === "active" ? "emerald" : "zinc"}
						title={
							source.status === "active"
								? "Activa en este dashboard"
								: "Reservada para crecimiento del módulo"
						}
					>
						{source.label}
						{source.status !== "active" ? " · planned" : ""}
					</Badge>
				))}
			</div>

			{notes.length ? (
				<ul className="space-y-1.5 text-xs text-zinc-500 dark:text-zinc-400">
					{notes.map((note) => (
						<li key={note} className="flex gap-2">
							<span className="mt-1.5 size-1 shrink-0 rounded-full bg-zinc-300 dark:bg-zinc-600" />
							<Text className="text-xs text-zinc-500 dark:text-zinc-400">
								{note}
							</Text>
						</li>
					))}
				</ul>
			) : null}
		</div>
	);
}
