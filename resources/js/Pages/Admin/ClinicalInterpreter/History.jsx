import { Link } from "@inertiajs/react";
import AdminLayout from "@/Layouts/AdminLayout";
import { Heading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import { Button } from "@/Components/Catalyst/button";
import { ChevronRightIcon } from "@heroicons/react/16/solid";

export default function History({ orders = [], meta }) {
	return (
		<AdminLayout title="Historial · AI Clinical Interpreter">
			<div className="space-y-6 pb-8">
				<nav className="flex flex-wrap items-center gap-1.5 text-[11px] uppercase tracking-[0.12em]">
					<Link
						href={route("admin.clinical-interpreter.index")}
						className="font-medium text-zinc-400 hover:text-famedic-light"
					>
						AI Clinical Interpreter
					</Link>
					<ChevronRightIcon className="size-3 text-zinc-300" />
					<span className="font-semibold text-zinc-700 dark:text-zinc-200">
						Historial
					</span>
				</nav>

				<div className="space-y-1">
					<div className="flex flex-wrap items-center gap-2">
						<Heading>{meta?.title || "Historial"}</Heading>
						<Badge color="zinc">Actividad</Badge>
					</div>
					<Text className="text-sm text-zinc-500">{meta?.description}</Text>
				</div>

				<div className="space-y-2">
					{orders.length === 0 ? (
						<p className="rounded-xl border border-dashed border-zinc-200 p-8 text-center text-sm text-zinc-400 dark:border-zinc-700">
							Sin actividad reciente.
						</p>
					) : (
						orders.map((order) => (
							<div
								key={order.id}
								className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900"
							>
								<div>
									<div className="flex flex-wrap items-center gap-2">
										<p className="text-sm font-semibold">
											Clinical Order #{order.id}
										</p>
										<Badge color="famedic" className="!text-[10px]">
											{order.status_label}
										</Badge>
									</div>
									<p className="mt-1 text-xs text-zinc-500">
										{order.patient_name || "Sin paciente"} ·{" "}
										{order.created_at
											? new Date(order.created_at).toLocaleString("es-MX")
											: "—"}{" "}
										· {order.operator?.name || "Operador"}
									</p>
								</div>
								<div className="flex items-center gap-3">
									<p className="text-sm font-medium">{order.total}</p>
									<Button outline href={order.show_url} className="!py-1 text-xs">
										Abrir
									</Button>
								</div>
							</div>
						))
					)}
				</div>
			</div>
		</AdminLayout>
	);
}
