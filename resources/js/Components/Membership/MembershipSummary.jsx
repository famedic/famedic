import Card from "@/Components/Card";
import { Badge } from "@/Components/Catalyst/badge";
import { Text } from "@/Components/Catalyst/text";
import { CreditCardIcon } from "@heroicons/react/24/outline";
import MembershipHolderCard from "@/Components/Membership/MembershipHolderCard";

function SummaryBlock({ title, description, children }) {
	return (
		<div className="space-y-3">
			<div>
				<h4 className="font-poppins font-semibold text-famedic-dark dark:text-white">
					{title}
				</h4>
				{description && (
					<Text className="text-sm text-zinc-500">{description}</Text>
				)}
			</div>
			{children}
		</div>
	);
}

function PlanSummaryCard({ plan }) {
	if (!plan) {
		return (
			<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
				<Text className="text-sm text-zinc-500">
					No hay información del plan disponible.
				</Text>
			</Card>
		);
	}

	return (
		<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
			<dl className="space-y-3 text-sm">
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Plan</dt>
					<dd className="font-medium">{plan.name}</dd>
				</div>
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Precio</dt>
					<dd className="font-medium">{plan.price}</dd>
				</div>
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Renovación</dt>
					<dd className="font-medium">{plan.renewalDate ?? "—"}</dd>
				</div>
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Estado</dt>
					<dd>
						<Badge
							color={
								plan.status === "Pagado"
									? "emerald"
									: plan.status === "Pendiente"
										? "amber"
										: "sky"
							}
						>
							{plan.status}
						</Badge>
					</dd>
				</div>
			</dl>
		</Card>
	);
}

function PaymentSummaryCard({ payment }) {
	if (!payment) {
		return (
			<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
				<Text className="text-sm text-zinc-500">
					No hay pagos registrados.
				</Text>
			</Card>
		);
	}

	return (
		<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
			<div className="mb-4 flex items-center gap-3">
				<div className="flex size-9 items-center justify-center rounded-xl bg-sky-50 text-sky-600">
					<CreditCardIcon className="size-5" />
				</div>
				<div>
					<p className="font-medium text-zinc-800 dark:text-slate-100">
						Último pago
					</p>
					<Text className="text-xs text-zinc-500">
						{payment.date} · {payment.time}
					</Text>
				</div>
			</div>
			<dl className="space-y-3 text-sm">
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Monto</dt>
					<dd className="font-semibold">{payment.amount}</dd>
				</div>
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Método</dt>
					<dd className="font-medium">{payment.method}</dd>
				</div>
				<div className="flex justify-between gap-4">
					<dt className="text-zinc-500">Estado</dt>
					<dd>
						<Badge
							color={
								payment.statusKey === "success" ? "emerald" : "amber"
							}
						>
							{payment.status}
						</Badge>
					</dd>
				</div>
			</dl>
		</Card>
	);
}

function QuickUsageCard() {
	return (
		<Card className="rounded-2xl p-5 ring-1 ring-slate-100">
			<Text className="text-sm text-zinc-500">
				Las estadísticas de uso estarán disponibles pronto. Mientras tanto,
				accede a telemedicina y asistencias desde Atención médica.
			</Text>
		</Card>
	);
}

export default function MembershipSummary({ holder, plan, payment }) {
	return (
		<div className="grid gap-6 lg:grid-cols-3">
			<SummaryBlock
				title="Información del titular"
				description="Datos de contacto y perfil."
			>
				<MembershipHolderCard holder={holder} />
			</SummaryBlock>

			<SummaryBlock
				title="Resumen del plan"
				description="Detalles de tu membresía actual."
			>
				<PlanSummaryCard plan={plan} />
			</SummaryBlock>

			<div className="space-y-6">
				<SummaryBlock
					title="Pago más reciente"
					description="Tu última transacción."
				>
					<PaymentSummaryCard payment={payment} />
				</SummaryBlock>

				<SummaryBlock title="Uso rápido" description="Actividad reciente.">
					<QuickUsageCard />
				</SummaryBlock>
			</div>
		</div>
	);
}
