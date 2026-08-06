import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

function OrderCard({ order }) {
	return (
		<a
			href={order.show_url}
			className="block rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-famedic-light dark:border-zinc-700 dark:bg-zinc-900"
		>
			<div className="flex flex-wrap items-center gap-2">
				<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
					#{order.id}
				</p>
				<Badge color="famedic" className="!text-[10px]">
					{order.status_label}
				</Badge>
			</div>
			<p className="mt-1 text-xs text-zinc-500">
				{order.patient_name || "Paciente no identificado"}
			</p>
			<div className="mt-3 flex flex-wrap gap-3 text-[11px] text-zinc-400">
				<span>{order.total}</span>
				<span>{order.studies_count} estudios</span>
			</div>
		</a>
	);
}

export default function Index({
	meta,
	quick_links = [],
	recent_orders = [],
	recent_interpretations = [],
}) {
	return (
		<AdminLayout title="AI Clinical Interpreter">
			<div className="space-y-8 pb-8">
				<nav
					aria-label="Breadcrumb"
					className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]"
				>
					<span className="font-medium text-zinc-400">IA</span>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						AI Clinical Interpreter
					</span>
				</nav>

				<section className="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-10">
					<div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_rgba(0,154,216,0.12),_transparent_55%)] dark:bg-[radial-gradient(ellipse_at_top_right,_rgba(0,154,216,0.18),_transparent_55%)]" />
					<div className="relative max-w-2xl space-y-4">
						<div className="flex flex-wrap items-center gap-2">
							<Heading>{meta?.product || "AI Clinical Interpreter"}</Heading>
							<Badge color="famedic">v1.0 Laboratorio</Badge>
							<Badge color="sky">Experimental</Badge>
						</div>
						<Text className="text-base text-zinc-600 dark:text-zinc-300">
							{meta?.tagline ||
								"Interpreta órdenes de laboratorio médico y conéctalas al catálogo Famedic."}
						</Text>
						<p className="text-sm text-zinc-500">
							Vision interpreta la orden · Famedic hace matching de estudios · el
							operador valida · Clinical Order prepara la cotización comercial.
						</p>
						<div className="flex flex-wrap gap-2 pt-2">
							<Button href={route("admin.clinical-interpreter.assistant")}>
								Nueva interpretación
							</Button>
							<Button
								outline
								href={route("admin.clinical-interpreter.orders.index")}
							>
								Ver Clinical Orders
							</Button>
						</div>
					</div>
				</section>

				<section className="space-y-3">
					<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
						Accesos rápidos
					</h2>
					<div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
						{quick_links.map((link) => (
							<a
								key={link.id}
								href={link.href}
								className="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:border-famedic-light dark:border-zinc-700 dark:bg-zinc-900"
							>
								<p className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
									{link.label}
								</p>
								<p className="mt-1 text-xs text-zinc-500">{link.description}</p>
							</a>
						))}
					</div>
				</section>

				<div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
					<section className="space-y-3">
						<div className="flex items-center justify-between gap-2">
							<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								Interpretaciones recientes
							</h2>
							<Link
								href={route("admin.clinical-interpreter.history")}
								className="text-xs font-medium text-famedic-light"
							>
								Ver historial
							</Link>
						</div>
						{recent_interpretations.length === 0 ? (
							<p className="rounded-xl border border-dashed border-zinc-200 p-6 text-sm text-zinc-400 dark:border-zinc-700">
								Aún no hay interpretaciones. Inicia una nueva.
							</p>
						) : (
							<div className="space-y-2">
								{recent_interpretations.map((order) => (
									<OrderCard key={`interp-${order.id}`} order={order} />
								))}
							</div>
						)}
					</section>

					<section className="space-y-3">
						<div className="flex items-center justify-between gap-2">
							<h2 className="text-sm font-semibold text-zinc-900 dark:text-zinc-50">
								Clinical Orders recientes
							</h2>
							<Link
								href={route("admin.clinical-interpreter.orders.index")}
								className="text-xs font-medium text-famedic-light"
							>
								Ver todas
							</Link>
						</div>
						{recent_orders.length === 0 ? (
							<p className="rounded-xl border border-dashed border-zinc-200 p-6 text-sm text-zinc-400 dark:border-zinc-700">
								Sin Clinical Orders todavía.
							</p>
						) : (
							<div className="space-y-2">
								{recent_orders.map((order) => (
									<OrderCard key={`order-${order.id}`} order={order} />
								))}
							</div>
						)}
					</section>
				</div>
			</div>
		</AdminLayout>
	);
}
