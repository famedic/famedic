import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import {
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from "@/Components/Catalyst/table";
import { ChevronRightIcon } from "@heroicons/react/16/solid";
import {
	AutomationNav,
	StatusBadge,
} from "@/Components/Admin/ActiveCampaign/Automations/AutomationMetrics";

export default function AutomationList({ items = [], meta, links = {} }) {
	return (
		<AdminLayout title="Marketing Intelligence · Automatizaciones">
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
					<Link
						href={route("admin.activecampaign.automations")}
						className="font-medium text-zinc-400 transition hover:text-famedic-light"
					>
						Automation Center
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300 dark:text-zinc-600" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Listado
					</span>
				</nav>

				<div className="flex flex-wrap items-start justify-between gap-4">
					<div className="space-y-2">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>Listado de automatizaciones</Heading>
							<Badge color="zinc">{meta?.total ?? items.length}</Badge>
						</div>
						<Text className="text-sm text-zinc-600 dark:text-zinc-400">
							Nombre, disparador, estado y próximas ejecuciones conocidas.
						</Text>
					</div>
					<AutomationNav active="list" links={links} />
				</div>

				<div className="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
					<Table bleed className="[--gutter:theme(spacing.6)]" dense>
						<TableHead>
							<TableRow>
								<TableHeader>Nombre</TableHeader>
								<TableHeader>Evento disparador</TableHeader>
								<TableHeader>Estado</TableHeader>
								<TableHeader className="hidden lg:table-cell">
									Última ejecución
								</TableHeader>
								<TableHeader className="hidden xl:table-cell">
									Próxima ejecución
								</TableHeader>
								<TableHeader className="text-right">Acciones</TableHeader>
							</TableRow>
						</TableHead>
						<TableBody>
							{items.map((row) => (
								<TableRow key={row.id}>
									<TableCell>
										<p className="font-medium text-zinc-900 dark:text-zinc-50">
											{row.name}
										</p>
										<p className="text-[11px] text-zinc-400">
											{row.source}
										</p>
									</TableCell>
									<TableCell>
										<p className="text-sm">{row.trigger_label}</p>
										<p className="text-[11px] text-zinc-400">
											{row.trigger_type}
										</p>
									</TableCell>
									<TableCell>
										<StatusBadge
											status={row.status}
											label={row.status_label}
										/>
									</TableCell>
									<TableCell className="hidden lg:table-cell text-xs">
										{row.last_run}
									</TableCell>
									<TableCell className="hidden xl:table-cell text-xs">
										{row.next_run}
									</TableCell>
									<TableCell className="text-right">
										<div className="flex flex-wrap justify-end gap-1">
											<Button href={row.detail_url} plain>
												Detalle
											</Button>
											<Button
												href={route(
													"admin.activecampaign.automations.builder",
													{ preset: row.id },
												)}
												plain
											>
												Builder
											</Button>
										</div>
									</TableCell>
								</TableRow>
							))}
						</TableBody>
					</Table>
				</div>
			</div>
		</AdminLayout>
	);
}
