import AdminLayout from "@/Layouts/AdminLayout";
import { Heading, Subheading } from "@/Components/Catalyst/heading";
import { Text } from "@/Components/Catalyst/text";
import { Link } from "@inertiajs/react";
import { BellAlertIcon, EnvelopeOpenIcon, ShieldCheckIcon } from "@heroicons/react/24/outline";

const simulators = [
	{
		key: "otp",
		title: "Simulador OTP",
		description:
			"Prueba el envío de códigos por SMS o correo con la misma notificación del flujo de resultados, sin afectar a pacientes.",
		href: () => route("admin.simulators.otp"),
		icon: ShieldCheckIcon,
	},
	{
		key: "gda",
		title: "Notificaciones GDA",
		description:
			"Simula webhooks de toma de muestra o resultados, consulta registros reales del pedido y reenvía correos al paciente.",
		href: () => route("admin.simulators.gda"),
		icon: BellAlertIcon,
	},
	{
		key: "emails",
		title: "Correos",
		description:
			"Abre el simulador de correos: listado por categoría y enlaces que abren en pestaña nueva con vista previa HTML (sin enviar correos).",
		href: () => route("admin.simulators.emails"),
		icon: EnvelopeOpenIcon,
	},
];

export default function SimulatorsIndex() {
	return (
		<AdminLayout title="Simuladores">
			<div className="space-y-6">
				<div>
					<Heading>Simuladores</Heading>
					<Text className="mt-2 max-w-3xl text-sm text-zinc-600 dark:text-zinc-400">
						Herramientas internas para validar integraciones y flujos sensibles en un entorno controlado,
						separado de las pantallas de pacientes.
					</Text>
				</div>

				<div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
					{simulators.map((item) => (
						<Link
							key={item.key}
							href={item.href()}
							className="group rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm transition hover:border-famedic-light/60 hover:shadow-md dark:border-slate-700 dark:bg-slate-900 dark:hover:border-famedic-light/40"
						>
							<div className="flex items-start gap-4">
								<div
									className={
										item.key === "emails"
											? "rounded-2xl bg-sky-100 p-3 dark:bg-sky-950/40"
											: item.key === "gda"
												? "rounded-2xl bg-violet-100 p-3 dark:bg-violet-950/40"
												: "rounded-2xl bg-emerald-100 p-3 dark:bg-emerald-950/40"
									}
								>
									<item.icon
										className={
											item.key === "emails"
												? "size-6 text-sky-800 dark:text-sky-200"
												: item.key === "gda"
													? "size-6 text-violet-800 dark:text-violet-200"
													: "size-6 text-emerald-700 dark:text-emerald-300"
										}
									/>
								</div>
								<div className="min-w-0">
									<Subheading>{item.title}</Subheading>
									<Text className="mt-2 text-sm text-zinc-600 dark:text-zinc-400">{item.description}</Text>
									<Text className="mt-3 text-sm font-medium text-famedic-light group-hover:underline">
										Abrir simulador →
									</Text>
								</div>
							</div>
						</Link>
					))}
				</div>
			</div>
		</AdminLayout>
	);
}
