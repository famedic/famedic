import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import EcommerceSummary from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceSummary";
import EcommerceToolbar from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceToolbar";
import EcommerceDistribution from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceDistribution";
import EcommerceTables from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceTables";
import EcommerceCharts from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceCharts";
import EcommerceDecision from "@/Components/Admin/ActiveCampaign/Ecommerce/EcommerceDecision";

function DeferredCharts() {
	const { charts } = usePage().props;
	return <EcommerceCharts charts={charts || null} />;
}

export default function EcommerceIntelligence({
	filters,
	summary,
	kpis,
	distribution,
	payment_methods,
	top_products,
	coupons,
	insights,
	recommendations,
	risks,
	suggested_actions,
	gaps,
	meta,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Ecommerce Intelligence">
			<div className="space-y-6 pb-6">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<Link
						href={route("admin.activecampaign.dashboard")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Marketing Intelligence
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Ecommerce Intelligence
					</span>
				</nav>

				<div className="space-y-2">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>Ecommerce Intelligence</Heading>
						<Badge color="famedic">Commerce</Badge>
						<Badge color="sky">Dirección</Badge>
					</div>
					<Text className="max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						{meta?.purpose ||
							"Consola ejecutiva comercial: Lab, Farmacia y Membresías."}
					</Text>
					{meta?.source_of_truth ? (
						<p className="text-[11px] text-zinc-400">
							Fuente: {meta.source_of_truth}
						</p>
					) : null}
				</div>

				<EcommerceToolbar filters={filters} meta={meta} />

				<EcommerceSummary summary={summary} kpis={kpis} />

				<EcommerceDistribution rows={distribution} />

				<EcommerceTables
					payment_methods={payment_methods}
					top_products={top_products}
					coupons={coupons}
				/>

				<Deferred data="charts" fallback={<EcommerceCharts charts={null} />}>
					<DeferredCharts />
				</Deferred>

				<EcommerceDecision
					insights={insights}
					recommendations={recommendations}
					risks={risks}
					suggested_actions={suggested_actions}
					gaps={gaps}
				/>
			</div>
		</AdminLayout>
	);
}
