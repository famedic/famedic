import { Deferred, Link, usePage } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import AnalyticsToolbar from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsToolbar";
import AnalyticsDomain from "@/Components/Admin/ActiveCampaign/Analytics/AnalyticsDomain";

function DomainBlock({ domain }) {
	const { charts } = usePage().props;
	return <AnalyticsDomain domain={domain} charts={charts} />;
}

export default function Analytics({
	filters,
	meta,
	domains = [],
	dashboardUrl,
	contactsUrl,
	journeyUrl,
	logsUrl,
	healthUrl,
}) {
	return (
		<AdminLayout title="Marketing Intelligence · Analytics">
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
						Analytics
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="max-w-3xl space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Analytics</Heading>
							<Badge color="famedic">Capacidad</Badge>
							<Badge color="sky">Decisión</Badge>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							{meta?.purpose ||
								"Dominios de decisión sobre la misma fuente de verdad del Dashboard."}
						</Text>
						<div className="flex flex-wrap gap-3 text-xs font-semibold text-famedic-light">
							{dashboardUrl ? <Link href={dashboardUrl}>Dashboard →</Link> : null}
							{contactsUrl ? <Link href={contactsUrl}>Contactos →</Link> : null}
							{journeyUrl ? <Link href={journeyUrl}>Journey →</Link> : null}
							{logsUrl ? <Link href={logsUrl}>Logs →</Link> : null}
							{healthUrl ? <Link href={healthUrl}>Health →</Link> : null}
						</div>
					</div>
				</div>

				<nav className="flex flex-wrap gap-2" aria-label="Dominios">
					{domains.map((domain) => (
						<a
							key={domain.id}
							href={`#domain-${domain.id}`}
							className="rounded-full border border-zinc-200 bg-white px-3 py-1 text-xs font-medium text-zinc-600 transition hover:border-famedic-light hover:text-famedic-light dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300"
						>
							{domain.label}
						</a>
					))}
				</nav>

				<AnalyticsToolbar filters={filters} meta={meta} />

				<Deferred
					data="charts"
					fallback={
						<div className="space-y-6">
							{domains.map((domain) => (
								<AnalyticsDomain
									key={domain.id}
									domain={domain}
									charts={null}
								/>
							))}
						</div>
					}
				>
					<div className="space-y-6">
						{domains.map((domain) => (
							<DomainBlock key={domain.id} domain={domain} />
						))}
					</div>
				</Deferred>
			</div>
		</AdminLayout>
	);
}
