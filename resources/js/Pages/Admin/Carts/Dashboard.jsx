import { useForm } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Text, Strong } from "@/Components/Catalyst/text";
import DashboardHeader from "@/Components/Admin/CartsDashboard/DashboardHeader";
import FiltersBar from "@/Components/Admin/CartsDashboard/FiltersBar";
import KpiCards from "@/Components/Admin/CartsDashboard/KpiCards";
import {
	DailyTrends,
	FunnelAndStages,
	PaymentsBlock,
	AppointmentsContactBlock,
	LaboratoriesCustomersBlock,
	TicketAndStudiesBlock,
} from "@/Components/Admin/CartsDashboard/AnalyticsBlocks";

export default function Dashboard({
	filters,
	filterOptions,
	kpis,
	operationalKpis,
	daily,
	funnel,
	payments,
	appointments,
	contact,
	laboratories,
	customerProfile,
	ticketAverages,
	topStudies,
	meta,
	cartsIndexUrl,
}) {
	const refreshForm = useForm({
		...filters,
		refresh: 1,
	});

	const handleRefresh = () => {
		refreshForm.get(route("admin.carts.dashboard"), {
			preserveState: false,
		});
	};

	return (
		<AdminLayout title="Dashboard de carritos">
			<div className="space-y-6">
				<DashboardHeader
					cartsIndexUrl={cartsIndexUrl}
					onRefresh={handleRefresh}
					refreshing={refreshForm.processing}
					generatedAt={meta?.generated_at}
				/>

				<FiltersBar filters={filters} filterOptions={filterOptions} />

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							KPIs principales
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Comparado contra{" "}
							{meta?.previous_period?.start_date} -{" "}
							{meta?.previous_period?.end_date}
						</Text>
					</div>
					<KpiCards kpis={kpis} columnsClassName="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6" />
				</section>

				<section className="space-y-3">
					<div>
						<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
							Operacion
						</h2>
						<Text className="text-xs text-zinc-500 dark:text-zinc-400">
							Las tarjetas enlazan al monitor solo cuando existe un filtro real en `/admin/carts`.
						</Text>
					</div>
					<KpiCards
						kpis={operationalKpis}
						cartsIndexUrl={cartsIndexUrl}
						columnsClassName="grid gap-4 md:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-6"
					/>
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Tendencia y montos
					</h2>
					<DailyTrends daily={daily} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Embudo y etapa
					</h2>
					<FunnelAndStages funnel={funnel} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Pagos
					</h2>
					<PaymentsBlock payments={payments} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Citas y llamadas
					</h2>
					<AppointmentsContactBlock appointments={appointments} contact={contact} />
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Marcas y clientes
					</h2>
					<LaboratoriesCustomersBlock
						laboratories={laboratories}
						customerProfile={customerProfile}
					/>
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Tickets y productos
					</h2>
					<TicketAndStudiesBlock
						ticketAverages={ticketAverages}
						topStudies={topStudies}
					/>
				</section>

				{meta?.definitions ? (
					<div className="rounded-xl border border-dashed border-zinc-300 p-4 text-xs text-zinc-500 dark:border-zinc-600 dark:text-zinc-400">
						<p className="font-medium text-zinc-700 dark:text-zinc-300">
							Definiciones
						</p>
						<ul className="mt-2 list-disc space-y-1 pl-4">
							<li>{meta.definitions.abandoned}</li>
							<li>{meta.definitions.conversion}</li>
							<li>{meta.definitions.cart_amounts}</li>
							<li>{meta.definitions.payments}</li>
						</ul>
						<Text className="mt-3 text-xs">
							<Strong>Nota:</Strong> los montos son snapshots de carrito y no se reportan como ingreso contable.
						</Text>
					</div>
				) : null}
			</div>
		</AdminLayout>
	);
}
